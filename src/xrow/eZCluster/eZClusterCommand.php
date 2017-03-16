<?php
namespace xrow\eZCluster;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class eZClusterCommand extends Command
{

    protected function configure()
    {
        $this->setName('ezcluster')
            ->setDescription('eZ Cluster main programm')
            ->addArgument('action', InputArgument::REQUIRED, 'Who do you want to do?')
            ->addArgument('arguments', InputArgument::IS_ARRAY, 'Parameters of the action');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
        
        CloudSDK::init();
        if ( !empty($input->getArgument('action') ) ) {
            $args = $input->getArgument('arguments');
            switch ($input->getArgument('action')) {
                case 'validatexml':
                    echo "Using XML LIB version " . LIBXML_DOTTED_VERSION . "\n";
                    try {
                        $file = xrowAWSSDK::CONFIG_FILE;
                        xrowClusterTools::validateXML($file);
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
                case 'migrate':
                    db::migrateDatabase($args[0], $args[1]);
                    break;
                case 'setupcrons':
                    ClusterNode::getInstance()->setupCrons();
                    break;
                case 'update':
                    if ($args[1]) {
                        ClusterNode::getInstance()->copyDataFromSource($args[0]);
                    }
                    break;
                case 'update-database':
                    if ($args[1]) {
                        ClusterNode::getInstance()->copyDataFromSource($args[0], true, false );
                    }
                    break;
                case 'update-storage':
                    if ($args[1]) {
                        ClusterNode::getInstance()->copyDataFromSource($args[0], false, true );
                    }
                    break;
                case 'bootstrap':
                    
                    if (isset($args[0])) {
                        $environment = new Resources\environment($args[0]);
                        $environment->setup();
                        ClusterNode::getInstance()->setupCrons();
                    } else {
                        foreach (Resources\environment::getList() as $environment) {
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
