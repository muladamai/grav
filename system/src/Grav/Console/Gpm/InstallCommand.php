<?php
namespace Grav\Console\Gpm;

use Grav\Common\Filesystem\Folder;
use Grav\Common\GPM\GPM;
use Grav\Common\GPM\Installer;
use Grav\Common\GPM\Response;
use Grav\Common\Utils;
use Grav\Console\ConsoleCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Yaml\Yaml;

define('GIT_REGEX', '/http[s]?:\/\/(?:.*@)?(github|bitbucket)(?:.org|.com)\/.*\/(.*)/');

/**
 * Class InstallCommand
 * @package Grav\Console\Gpm
 */
class InstallCommand extends ConsoleCommand
{
    /** @var */
    protected $data;

    /** @var GPM */
    protected $gpm;

    /** @var */
    protected $destination;

    /** @var */
    protected $file;

    /** @var */
    protected $tmp;

    /** @var */
    protected $local_config;

    /** @var bool */
    protected $use_symlinks;

    /** @var array */
    protected $demo_processing = [];

    /**
     *
     */
    protected function configure()
    {
        $this
            ->setName("install")
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force re-fetching the data from remote'
            )
            ->addOption(
                'all-yes',
                'y',
                InputOption::VALUE_NONE,
                'Assumes yes (or best approach) instead of prompting'
            )
            ->addOption(
                'destination',
                'd',
                InputOption::VALUE_OPTIONAL,
                'The destination where the package should be installed at. By default this would be where the grav instance has been launched from',
                GRAV_ROOT
            )
            ->addArgument(
                'package',
                InputArgument::IS_ARRAY | InputArgument::REQUIRED,
                'The package(s) that are desired to be installed. Use the "index" command for a list of packages'
            )
            ->setDescription("Performs the installation of plugins and themes")
            ->setHelp('The <info>install</info> command allows to install plugins and themes');
    }

    /**
     * Allows to set the GPM object, used for testing the class
     *
     * @param $gpm
     */
    public function setGpm($gpm)
    {
        $this->gpm = $gpm;
    }

    /**
     * @return int|null|void|bool
     */
    protected function serve()
    {
        $this->gpm = new GPM($this->input->getOption('force'));
        $this->destination = realpath($this->input->getOption('destination'));

        $packages = array_map('strtolower', $this->input->getArgument('package'));
        $this->data = $this->gpm->findPackages($packages);

        if (false === $this->isWindows() && @is_file(getenv("HOME") . '/.grav/config')) {
            $local_config_file = exec('eval echo ~/.grav/config');
            if (file_exists($local_config_file)) {
                $this->local_config = Yaml::parse($local_config_file);
            }
        }

        if (
            !Installer::isGravInstance($this->destination) ||
            !Installer::isValidDestination($this->destination, [Installer::EXISTS, Installer::IS_LINK])
        ) {
            $this->output->writeln("<red>ERROR</red>: " . Installer::lastErrorMsg());
            exit;
        }

        $this->output->writeln('');

        if (!$this->data['total']) {
            $this->output->writeln("Nothing to install.");
            $this->output->writeln('');
            exit;
        }

        if (count($this->data['not_found'])) {
            $this->output->writeln("These packages were not found on Grav: <red>" . implode('</red>, <red>',
                    array_keys($this->data['not_found'])) . "</red>");
        }

        unset($this->data['not_found']);
        unset($this->data['total']);

        if (isset($this->local_config)) {
            // Symlinks available, ask if Grav should use them

            $this->use_symlinks = false;
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Should Grav use the symlinks if available? [y|N] ', false);

            if ($helper->ask($this->input, $this->output, $question)) {
                $this->use_symlinks = true;
            }
        }

        $this->output->writeln('');

        try {
            $dependencies = $this->gpm->getDependencies($packages);
        } catch (\Exception $e) {
            //Error out if there are incompatible packages requirements and tell which ones, and what to do
            //Error out if there is any error in parsing the dependencies and their versions, and tell which one is broken
            $this->output->writeln("<red>" . $e->getMessage() . "</red>");
            return false;
        }

        if ($dependencies) {
            //First, check for Grav dependency. If a dependency requires Grav > the current version, abort and tell.
            if (isset($dependencies['grav'])) {
                if (version_compare($this->gpm->calculateVersionNumberFromDependencyVersion($dependencies['grav']), GRAV_VERSION) === 1) {
                    //Needs a Grav update first
                    $this->output->writeln("<red>One of the package dependencies requires Grav " . $dependencies['grav'] . ". Please update Grav first with `bin/gpm selfupgrade`</red>");
                    return false;
                }
                unset($dependencies['grav']);
            }

            try {
                $this->installDependencies($dependencies, 'install', "The following dependencies need to be installed...");
                $this->installDependencies($dependencies, 'update',  "The following dependencies need to be updated...");
                $this->installDependencies($dependencies, 'ignore',  "The following dependencies can be updated as there is a newer version, but it's not mandatory...", false);
            } catch (\Exception $e) {
                $this->output->writeln("<red>Installation aborted</red>");
                return false;
            }

            $this->output->writeln("<green>Dependencies are OK</green>");
            $this->output->writeln("");
        }

        //We're done installing dependencies. Install the actual packages
        foreach ($this->data as $data) {
            foreach ($data as $packageName => $package) {
                if (in_array($packageName, array_keys($dependencies))) {
                    $this->output->writeln("<green>Package " . $packageName . " already installed as dependency</green>");
                } else {
                    $is_valid_destination = Installer::isValidDestination($this->destination . DS . $package->install_path);
                    if ($is_valid_destination || Installer::lastErrorCode() == Installer::NOT_FOUND) {
                        $this->processPackage($package, true);
                    } else {
                        if (Installer::lastErrorCode() == Installer::EXISTS) {
                            $helper = $this->getHelper('question');
                            $question = new ConfirmationQuestion("The package <cyan>$packageName</cyan> is already installed, overwrite? [y|N] ", false);

                            if ($helper->ask($this->input, $this->output, $question)) {
                                $this->processPackage($package, true);
                            } else {
                                $this->output->writeln("<yellow>Package " . $packageName . " not overwritten</yellow>");
                            }
                        }
                    }
                }
            }
        }

        if (count($this->demo_processing) > 0) {
            foreach ($this->demo_processing as $package) {
                $this->installDemoContent($package);
            }
        }

        // clear cache after successful upgrade
        $this->clearCache();

        return true;
    }

    /**
     * Given a $dependencies list, filters their type according to $type and
     * shows $message prior to listing them to the user. Then asks the user a confirmation prior
     * to installing them.
     *
     * @param array  $dependencies The dependencies array
     * @param string $type         The type of dependency to show: install, update, ignore
     * @param string $message      A message to be shown prior to listing the dependencies
     * @param bool   $required     A flag that determines if the installation is required or optional
     *
     * @throws \Exception
     */
    public function installDependencies($dependencies, $type, $message, $required = true) {
        $packages = array_filter($dependencies, function ($action) use ($type) { return $action === $type; });
        if (count($packages) > 0) {
            $this->output->writeln($message);

            foreach ($packages as $dependencyName => $dependencyVersion) {
                $this->output->writeln("  |- Package <cyan>" . $dependencyName . "</cyan>");
            }

            $this->output->writeln("");

            $helper = $this->getHelper('question');

            if ($type == 'install') {
                $questionAction = 'Install';
            } else {
                $questionAction = 'Update';
            }

            if (count($packages) == 1) {
                $questionArticle = 'this';
            } else {
                $questionArticle = 'these';
            }

            if (count($packages) == 1) {
                $questionNoun = 'package';
            } else {
                $questionNoun = 'packages';
            }

            $question = new ConfirmationQuestion("$questionAction $questionArticle $questionNoun? [y|N] ", false);

            if ($helper->ask($this->input, $this->output, $question)) {
                foreach ($packages as $dependencyName => $dependencyVersion) {
                    $package = $this->gpm->findPackage($dependencyName);
                    $this->processPackage($package, true);
                }
                $this->output->writeln('');
            } else {
                if ($required) {
                    throw new \Exception();
                }
            }
        }
    }

    /**
     * @param      $package
     * @param bool $skip_prompt
     */
    private function processPackage($package, $skip_prompt = false)
    {
        if (!$package) {
            $this->output->writeln("<red>Package not found on the GPM!</red>  ");
            $this->output->writeln('');
            return;
        }

        $symlink = false;
        if ($this->use_symlinks) {
            if ($this->getSymlinkSource($package) || !isset($package->version)) {
                $symlink = true;
            }
        }

        $symlink ? $this->processSymlink($package, $skip_prompt) : $this->processGpm($package, $skip_prompt);

        $this->processDemo($package);
    }

    /**
     * Add package to the queue to process the demo content, if demo content exists
     *
     * @param $package
     */
    private function processDemo($package)
    {
        $demo_dir = $this->destination . DS . $package->install_path . DS . '_demo';
        if (file_exists($demo_dir)) {
            $this->demo_processing[] = $package;
        }
    }

    /**
     * Prompt to install the demo content of a package
     *
     * @param $package
     */
    private function installDemoContent($package)
    {
        $demo_dir = $this->destination . DS . $package->install_path . DS . '_demo';

        if (file_exists($demo_dir)) {
            $dest_dir = $this->destination . DS . 'user';
            $pages_dir = $dest_dir . DS . 'pages';

            // Demo content exists, prompt to install it.
            $this->output->writeln("<white>Attention: </white><cyan>" . $package->name . "</cyan> contains demo content");
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Do you wish to install this demo content? [y|N] ', false);

            if (!$helper->ask($this->input, $this->output, $question)) {
                $this->output->writeln("  '- <red>Skipped!</red>  ");
                $this->output->writeln('');

                return;
            }

            // if pages folder exists in demo
            if (file_exists($demo_dir . DS . 'pages')) {
                $pages_backup = 'pages.' . date('m-d-Y-H-i-s');
                $question = new ConfirmationQuestion('This will backup your current `user/pages` folder to `user/' . $pages_backup . '`, continue? [y|N]', false);

                if (!$helper->ask($this->input, $this->output, $question)) {
                    $this->output->writeln("  '- <red>Skipped!</red>  ");
                    $this->output->writeln('');

                    return;
                }

                // backup current pages folder
                if (file_exists($dest_dir)) {
                    if (rename($pages_dir, $dest_dir . DS . $pages_backup)) {
                        $this->output->writeln("  |- Backing up pages...    <green>ok</green>");
                    } else {
                        $this->output->writeln("  |- Backing up pages...    <red>failed</red>");
                    }
                }
            }

            // Confirmation received, copy over the data
            $this->output->writeln("  |- Installing demo content...    <green>ok</green>                             ");
            Folder::rcopy($demo_dir, $dest_dir);
            $this->output->writeln("  '- <green>Success!</green>  ");
            $this->output->writeln('');
        }
    }

    /**
     * @param $package
     *
     * @return array
     */
    private function getGitRegexMatches($package)
    {
        if (isset($package->repository)) {
            $repository = $package->repository;
        } else {
            return false;
        }

        preg_match(GIT_REGEX, $repository, $matches);

        return $matches;
    }

    /**
     * @param $package
     *
     * @return bool|string
     */
    private function getSymlinkSource($package)
    {
        $matches = $this->getGitRegexMatches($package);

        foreach ($this->local_config as $path) {
            if (Utils::endsWith($matches[2], '.git')) {
                $repo_dir = preg_replace('/\.git$/', '', $matches[2]);
            } else {
                $repo_dir = $matches[2];
            }

            $from = rtrim($path, '/') . '/' . $repo_dir;

            if (file_exists($from)) {
                return $from;
            }
        }

        return false;
    }

    /**
     * @param      $package
     * @param bool $skip_prompt
     */
    private function processSymlink($package, $skip_prompt = false)
    {

        exec('cd ' . $this->destination);

        $to = $this->destination . DS . $package->install_path;
        $from = $this->getSymlinkSource($package);

        $this->output->writeln("Preparing to Symlink <cyan>" . $package->name . "</cyan>");
        $this->output->write("  |- Checking source...  ");

        if (file_exists($from)) {
            $this->output->writeln("<green>ok</green>");

            $this->output->write("  |- Checking destination...  ");
            $checks = $this->checkDestination($package, $skip_prompt);

            if (!$checks) {
                $this->output->writeln("  '- <red>Installation failed or aborted.</red>");
                $this->output->writeln('');
            } else {
                if (file_exists($to)) {
                    $this->output->writeln("  '- <red>Symlink cannot overwrite an existing package, please remove first</red>");
                    $this->output->writeln('');
                } else {
                    symlink($from, $to);

                    // extra white spaces to clear out the buffer properly
                    $this->output->writeln("  |- Symlinking package...    <green>ok</green>                             ");

                    $this->output->writeln("  '- <green>Success!</green>  ");
                    $this->output->writeln('');
                }


            }

            return;
        }

        $this->output->writeln("<red>not found!</red>");
        $this->output->writeln("  '- <red>Installation failed or aborted.</red>");
    }

    /**
     * @param      $package
     * @param bool $skip_prompt
     */
    private function processGpm($package, $skip_prompt = false)
    {
        $version = isset($package->available) ? $package->available : $package->version;

        $this->output->writeln("Preparing to install <cyan>" . $package->name . "</cyan> [v" . $version . "]");

        $this->output->write("  |- Downloading package...     0%");
        $this->file = $this->downloadPackage($package);

        $this->output->write("  |- Checking destination...  ");
        $checks = $this->checkDestination($package, $skip_prompt);

        if (!$checks) {
            $this->output->writeln("  '- <red>Installation failed or aborted.</red>");
            $this->output->writeln('');
        } else {
            $this->output->write("  |- Installing package...  ");
            $installation = $this->installPackage($package);
            if (!$installation) {
                $this->output->writeln("  '- <red>Installation failed or aborted.</red>");
                $this->output->writeln('');
            } else {
                $this->output->writeln("  '- <green>Success!</green>  ");
                $this->output->writeln('');
            }
        }
    }

    /**
     * @param $package
     *
     * @return string
     */
    private function downloadPackage($package)
    {
        $this->tmp = CACHE_DIR . DS . 'tmp/Grav-' . uniqid();
        $filename = $package->slug . basename($package->zipball_url);
        $output = Response::get($package->zipball_url, [], [$this, 'progress']);

        Folder::mkdir($this->tmp);

        $this->output->write("\x0D");
        $this->output->write("  |- Downloading package...   100%");
        $this->output->writeln('');

        file_put_contents($this->tmp . DS . $filename, $output);

        return $this->tmp . DS . $filename;
    }

    /**
     * @param      $package
     *
     * @param bool $skip_prompt
     *
     * @return bool
     */
    private function checkDestination($package, $skip_prompt = false)
    {
        $question_helper = $this->getHelper('question');

        if (!$skip_prompt) {
            $skip_prompt = $this->input->getOption('all-yes');
        }

        Installer::isValidDestination($this->destination . DS . $package->install_path);

        if (Installer::lastErrorCode() == Installer::EXISTS) {
            if (!$skip_prompt) {
                $this->output->write("\x0D");
                $this->output->writeln("  |- Checking destination...  <yellow>exists</yellow>");

                $question = new ConfirmationQuestion("  |  '- The package is already installed, do you want to overwrite it? [y|N] ",
                    false);
                $answer = $question_helper->ask($this->input, $this->output, $question);

                if (!$answer) {
                    $this->output->writeln("  |     '- <red>You decided to not overwrite the already installed package.</red>");

                    return false;
                }
            }
        }

        if (Installer::lastErrorCode() == Installer::IS_LINK) {
            $this->output->write("\x0D");
            $this->output->writeln("  |- Checking destination...  <yellow>symbolic link</yellow>");

            if ($skip_prompt) {
                $this->output->writeln("  |     '- <yellow>Skipped automatically.</yellow>");

                return false;
            }

            $question = new ConfirmationQuestion("  |  '- Destination has been detected as symlink, delete symbolic link first? [y|N] ",
                false);
            $answer = $question_helper->ask($this->input, $this->output, $question);

            if (!$answer) {
                $this->output->writeln("  |     '- <red>You decided to not delete the symlink automatically.</red>");

                return false;
            } else {
                unlink($this->destination . DS . $package->install_path);
            }
        }

        $this->output->write("\x0D");
        $this->output->writeln("  |- Checking destination...  <green>ok</green>");

        return true;
    }

    /**
     * @param $package
     *
     * @return bool
     */
    private function installPackage($package)
    {
        $type = $package->package_type;

        Installer::install($this->file, $this->destination, ['install_path' => $package->install_path, 'theme' => (($type == 'themes'))]);
        $error_code = Installer::lastErrorCode();
        Folder::delete($this->tmp);

        if ($error_code & (Installer::ZIP_OPEN_ERROR | Installer::ZIP_EXTRACT_ERROR)) {
            $this->output->write("\x0D");
            // extra white spaces to clear out the buffer properly
            $this->output->writeln("  |- Installing package...    <red>error</red>                             ");
            $this->output->writeln("  |  '- " . Installer::lastErrorMsg());

            return false;
        }

        $this->output->write("\x0D");
        // extra white spaces to clear out the buffer properly
        $this->output->writeln("  |- Installing package...    <green>ok</green>                             ");

        return true;
    }

    /**
     * @param $progress
     */
    public function progress($progress)
    {
        $this->output->write("\x0D");
        $this->output->write("  |- Downloading package... " . str_pad($progress['percent'], 5, " ",
                STR_PAD_LEFT) . '%');
    }
}
