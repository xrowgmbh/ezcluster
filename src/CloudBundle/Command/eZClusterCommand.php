<?php
namespace CloudBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use xrow\eZCluster\CloudSDK as CloudSDK;
use xrow\eZCluster\ClusterNode as ClusterNode;
use xrow\eZCluster\ClusterTools as ClusterTools;
use xrow\eZCluster\Resources\environment as Environment;

class eZClusterCommand extends Command
{

    protected function configure()
    {
        $this->setName('ezcluster')
            ->setDescription('eZ Cluster main programm')
            ->addArgument('arguments', InputArgument::IS_ARRAY, 'What action do you want to perform?');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);

        CloudSDK::init();
        if ($args = $input->getArgument('arguments')) {
            switch ($args[0]) {
                case 'validatexml':
                    CloudSDK::init();
                    echo "Using XML LIB version " . LIBXML_DOTTED_VERSION . "\n";
                    try {
                        ClusterTools::validateXML(CloudSDK::CONFIG_FILE);
                        echo $file . " is valid.\n";
                    } catch (Exception $e) {
                        echo $e->getMessage();
                    }
                    
                    break;
                case 'start':
                    $node = new ClusterNode();
                    $node->startServices();
                    break;
                case 'stop':
                    $node = new ClusterNode();
                    $node->stopServices();
                    break;
                case 'restart':
                    $node = new ClusterNode();
                    $node->stopServices();
                    $node->startServices();
                    break;
                case 'init':
                    CloudSDK::init(true);
                    break;
                case 'remove':
                    $class = $args[1];
                    $obj = new $class($args[2]);
                    $obj->delete();
                    break;
                case 'migrate':
                    db::migrateDatabase($args[1], $args[2]);
                    break;
                case 'sync':
                    ClusterNode::getInstance()->sync();
                    break;
                case 'setupcrons':
                    ClusterNode::getInstance()->setupCrons();
                    break;
                case 'update':
                    if ($args[1]) {
                        ClusterNode::getInstance()->copyDataFromSource($args[1]);
                    }
                    break;
                case 'update-database':
                    if ($args[1]) {
                        ClusterNode::getInstance()->copyDataFromSource($args[1], true, false );
                    }
                    break;
                case 'update-storage':
                    if ($args[1]) {
                        ClusterNode::getInstance()->copyDataFromSource($args[1], false, true );
                    }
                    break;
                case 'bootstrap':
                    
                    if (isset($args[1])) {
                        $environment = new Environment($args[1]);
                        $environment->setup();
                        ClusterNode::getInstance()->setupCrons();
                    } else {
                        foreach (Environment::getList() as $environment) {
                            $environment->clean();
                            $environment->setup();
                            ClusterNode::getInstance()->setupCrons();
                        }
                    }
                    break;
                default:
                    $output->writeln('<error>Choose one action</error>');
                    break;
            }
        }
        
        if ($input->getOption('help')) {
            $text = strtoupper($text);
        }
    }
}
