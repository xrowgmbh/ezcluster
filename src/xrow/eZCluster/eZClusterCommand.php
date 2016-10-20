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
                        $environment = new Resources\environment($args[1]);
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
                case 'csr':
                    ClusterNode::getInstance()->csr();
                    break;
                case 'verify':
                    $emailaddress = $args[1];
                    if (empty($emailaddress)) {
                        throw new Exception("Please provide an email address.");
                    }
                    $email = xrowAWSSDK::factoryAWS('AmazonSES');
                    $response = $email->verify_email_address($emailaddress);
                    if (! $response->isOK()) {
                        var_dump($response);
                    }
                    break;
                case 'send':
                    $emailaddress = $args[1];
                    $subject = $args[2];
                    $file = $args[3];
                    xrowClusterTools::sendMail($emailaddress, file_get_contents($file), $subject);
                    break;
                case 'vagrant':
                    xrowClusterTools::vagrant();
                    break;
                case 'load':
                    switch ($args[1]) {
                        case 'snapshot':
                            $diskpath = "/dev/xvdl";
                            $vol = volume::createFromSnapshot($args[2]);
                            $vol->attach($diskpath);
                            break;
                    }
                    break;
                case 'test':
                    echo "Test some stuff\n";
                    $diskpath = "/dev/xvdl";
                    try {
                        $vol = volume::getByPath($diskpath);
                    } catch (Exception $e) {
                        echo "No volume attached to $diskpath";
                    }
                    if ($vol === false) {
                        $vol = volume::create(10);
                        $vol->attach($diskpath);
                    } else {
                        $vol->detach();
                    }
                    break;
                case 'schemasave':
                    $service = new xroweZClusterService();
                    $service->save();
                    break;
                case 'amis':
                    $service = new xroweZClusterService();
                    $service->amis();
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
