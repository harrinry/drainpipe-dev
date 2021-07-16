<?php

declare(strict_types=1);

namespace Lullabot\DrainpipeDev;

use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Process\Process;

class DevScaffoldInstallerPlugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * Composer instance configuration.
     *
     * @var Config
     */
    protected $config;

    /**
     * {@inheritDoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->io = $io;
        $this->config = $composer->getConfig();
    }

    /**
     * {@inheritDoc}
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstallCmd',
            ScriptEvents::POST_UPDATE_CMD => 'onPostUpdateCmd',
        ];
    }

    /**
     * Handle post install command events.
     *
     * @param Event $event the event to handle
     */
    public function onPostInstallCmd(Event $event)
    {
        $this->installDevTaskfile();
        $this->installDdevSeleniumConfig();
        $this->installNightwatchConfig();
    }

    /**
     * Handle post update command events.
     *
     * @param event $event The event to handle
     */
    public function onPostUpdateCmd(Event $event)
    {
        $this->installDevTaskfile();
        $this->installDdevSeleniumConfig();
        $this->installNightwatchConfig();
    }

    private function installScaffoldFile(string $source, string $destination): void
    {
        $vendor = $this->config->get('vendor-dir');
        $filePath = "$vendor/lullabot/drainpipe-dev/scaffold/$source";

        if (!file_exists("./$destination")) {
            $this->io->write("<info>Creating initial $destination...</info>");
            $fs = new Filesystem();
            $fs->copy(
                $filePath,
                "./$destination"
            );
        }
    }

    /**
     * Copies Taskfile.dev.yml from the scaffold directory if it doesn't yet exist.
     */
    private function installDevTaskfile(): void
    {
        $this->installScaffoldFile('Taskfile.dev.yml', 'Taskfile.dev.yml');
        $vendor = $this->config->get('vendor-dir');
        $taskfilePath = "$vendor/lullabot/drainpipe-dev/scaffold/Taskfile.dev.yml";
        $scaffoldTaskfile = Yaml::parseFile($taskfilePath);
        $projectTaskfile = Yaml::parseFile("./Taskfile.dev.yml");
        foreach ($scaffoldTaskfile['includes'] as $key => $value) {
            if (empty($projectTaskfile['includes'][$key]) || $projectTaskfile['includes'][$key] !== $value) {
                $this->io->warning(
                    'Taskfile.dev.yml has either been customized or requires review.'
                );
                $this->io->warning(
                    sprintf(
                        'Compare Taskfile.dev.yml includes in the root of your repository with %s and update as needed.',
                        $taskfilePath
                    )
                );
                break;
            }
        }
    }

    /**
     * Copies docker-compose.selenium.yaml from the scaffold directory if it doesn't yet exist.
     */
    private function installDdevSeleniumConfig(): void
    {
        if (file_exists('./.ddev/config.yaml')) {
            $destination = '.ddev/docker-compose.selenium.yaml';
            $this->installScaffoldFile('docker-compose.selenium.yaml', $destination);
            // Make sure Selenium can access the Drupal site.
            $ddevConfig = Yaml::parseFile('./.ddev/config.yaml');
            if (!is_array($ddevConfig['web_environment']) || !in_array('NIGHTWATCH_DRUPAL_URL=http://web', $ddevConfig['web_environment'])) {
                $this->io->warning(
                    'You must run the following and then restart DDEV: ddev config --web-environment="NIGHTWATCH_DRUPAL_URL=http://web"'
                );
            }
            else {
                $this->io->warning('DDEV Configuration has been updated, please restart');
            }
            // Check if the file has deviated from the scaffold.
            $vendor = $this->config->get('vendor-dir');
            $filePath = "$vendor/lullabot/drainpipe-dev/scaffold/docker-compose.selenium.yaml";
            if (sha1_file($filePath) !== sha1_file($destination)) {
                $this->io->warning(
                    sprintf('%s has either been customized or requires review.',
                    $destination
                    )
                );
                $this->io->warning(
                    sprintf(
                        'Compare %s with %s and update as needed.',
                        $destination,
                        $filePath
                    )
                );

            }
        }
    }

    /**
     * Copies nightwatch.conf.js from the scaffold directory if it doesn't yet exist.
     */
    private function installNightwatchConfig(): void
    {
        if (!file_exists('./nightwatch.conf.js')) {
            $this->installScaffoldFile('nightwatch.conf.js', 'nightwatch.conf.js');
        }
        // Install an example test if none exist.
        if (!file_exists('./test/nightwatch')) {
            $fs = new Filesystem();
            $fs->ensureDirectoryExists('./test/nightwatch');
            $this->installScaffoldFile('example.nightwatch.js', 'test/nightwatch/example.nightwatch.js');
        }
        // Install Nightwatch dependencies if there is no package.json.
        $dependencies = [
            '@lullabot/nightwatch-drupal-commands@https://github.com/Lullabot/nightwatch-drupal-commands.git#main',
            'nightwatch',
            'nightwatch-accessibility',
        ];
        if (!file_exists('./package.json')) {
            $yarn = new Process(['yarn', 'set', 'version', 'berry']);
            $yarn->run(function($type, $buffer) {
                if (Process::ERR === $type) {
                    $this->io->write('ERR > '.$buffer);
                } else {
                    $this->io->write('OUT > '.$buffer);
                }
            });
            $yarn = new Process(['yarn', 'init']);
            $yarn->run(function($type, $buffer) {
                if (Process::ERR === $type) {
                    $this->io->write('ERR > '.$buffer);
                } else {
                    $this->io->write('OUT > '.$buffer);
                }
            });
        }
        // Add dependencies.
        if (file_exists('yarn.lock')) {
            $yarn = new Process(array_merge(['yarn', 'add'], $dependencies, ['--dev']));
            $yarn->run(function($type, $buffer) {
                if (Process::ERR === $type) {
                    $this->io->write('ERR > '.$buffer);
                } else {
                    $this->io->write('OUT > '.$buffer);
                }
            });
        } else if (file_exists('package-lock.json')) {
            $npm = new Process(array_merge(['npm', 'install'], $dependencies, ['--save-dev']));
            $npm->run(function($type, $buffer) {
                if (Process::ERR === $type) {
                    $this->io->write('ERR > '.$buffer);
                } else {
                    $this->io->write('OUT > '.$buffer);
                }
            });
        } else {
            $this->io->warning(
                sprintf('Yarn or NPM lockfile not found, please manually install Nightwatch dependencies %s',
                    implode(', ', $dependencies)
                )
            );
        }
    }
}
