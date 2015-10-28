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
                case 'reboot':
                    $node = instance::byName($args[1]);
                    if ($node) {
                        echo "rebooting $name.\n";
                        $node->reboot();
                    }
                    break;
                case 'shutdowncheck':
                    $node = new xroweZClusterNode();
                    if (! xrowClusterTools::isDateTimeinRange($node->getActivePeriods())) {
                        echo "shuting down.\n";
                        $node->stop();
                    } else {
                        echo "Not the right time to shutdown.\n";
                    }
                    break;
                case 'deploy':
                    echo "Starting Deployment\n";
                    try {
                        xrowClusterTools::deploy();
                    } catch (Exception $e) {
                        echo $e->getMessage();
                    }
                    
                    break;
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
                case 'terminate':
                    $node = new ClusterNode();
                    $node->terminate();
                    break;
                case 'createimage':
                    echo "Creating Image.\n";
                    $node = new ClusterNode();
                    $node->stopServices();
                    $date = new DateTime();
                    image::create('eZ Cluster Node (' . $date->format("Y-m-d-Hi-s") . ") ");
                    $node->startServices();
                    break;
                case 'createraw':
                    $dist = (empty($args[1])) ? 'el7' : $args[1];
                    
                    $diskpath = "/opt";
                    echo "Creating OS Image.\n";
                    
                    $sizeInMB = 5120;
                    $bytes = $sizeInMB * 1024 * 1024;
                    $blocksize = 512;
                    $sectors = 63;
                    $heads = 255;
                    $blocks = $bytes / $blocksize;
                    $cylinders = $blocks / ($sectors * $heads);
                    $cylinders = (int) $cylinders;
                    echo "Bytes: " . $bytes . "\n";
                    echo "MB: " . $sizeInMB . "\n";
                    echo "Blocksize: " . $blocksize . "\n";
                    echo "Blocks: 10485760  real ... " . $blocks . "\n";
                    echo "Cylinders: " . $cylinders . "\n";
                    echo "#############################";
                    system("umount /opt");
                    system("losetup -d /dev/loop1");
                    system("losetup -d /dev/loop0");
                    system("rm -Rf /tmp/image.img");
                    system("rm -Rf /tmp/fdisk.txt");
                    system("rm -Rf /tmp/grub.txt");
                    system("mkdir $diskpath");
                    file_put_contents("/tmp/fdisk.txt", "n
p
1
            
            
t
83
w
");
                    system("qemu-img create -f raw /tmp/image.img 5G");
                    system("parted /tmp/image.img mklabel msdos");
                    system("losetup -f /tmp/image.img");
                    system("cat /tmp/fdisk.txt | fdisk /dev/loop0");
                    system("losetup -o 32256 -f /dev/loop0");
                    system('echo "y" | mkfs.ext4 -m 0 /dev/loop1');
                    system('tune2fs -L "/" /dev/loop1');
                    system('losetup -d /dev/loop1');
                    system('losetup -d /dev/loop0');
                    system("mount -t ext4 -o loop,offset=32256 /tmp/image.img /opt");
                    
                    system("mkdir -p /opt/boot/grub");
                    system("ln -s ./grub.conf /opt/boot/grub/menu.lst");
                    // ystem( "cp /usr/share/grub/x86_64-redhat/stage* /opt/boot/grub" );
                    // ystem( "umount /opt" );
                    
                    // geometry drive [cylinder head sector [total_sector]]
                    file_put_contents("/tmp/grub.txt", "device (hd0) /tmp/image.img
geometry (hd0) 652 255 63
root (hd0,0)
setup (hd0)
quit
");
                    system("cat /tmp/grub.txt | /sbin/grub --batch --device-map=/opt/boot/grub/device.map");
                    
                    xrowClusterTools::createOS2($dist, $diskpath, '/dev/loop0');
                    
                    system("umount /opt");
                    system("reboot");
                    // ystem( "rm -Rf /tmp/image.vdi" );
                    // ystem( "VBoxManage convertfromraw /tmp/image.img /tmp/image.vdi --format vdi" );
                    
                    break;
                case 'createfromdisk':
                    $dist = (empty($args[1])) ? 'el6' : $args[1];
                    $diskpath = "/dev/xvdl";
                    echo "Creating OS Image.\n";
                    try {
                        $vol = volume::getByPath($diskpath);
                        $date = new DateTime();
                        $img = image::createFromVolume($vol, null, 'eZ Cluster ' . strtoupper($dist) . ' (' . $date->format("Y-m-d-Hi-s") . ") ");
                        echo "Created AMI " . $img->id . "\n";
                    } catch (Exception $e) {
                        echo "No volume attached to $diskpath";
                    }
                    
                    break;
                case 'create':
                    $dist = (empty($args[1])) ? 'el6' : $args[1];
                    $GLOBALS['DISABLE_DISKMAP'] = 1;
                    $diskpath = "/dev/xvdh";
                    $node = new ClusterNode();
                    $node->stopServices();
                    echo "Creating OS Image.\n";
                    try {
                        $vol = volume::getByPath($diskpath);
                    } catch (Exception $e) {
                        echo "No volume attached to $diskpath";
                    }
                    if ($vol === false) {
                        $vol = volume::create(10);
                        $vol->attach($diskpath);
                    }
                    
                    xrowClusterTools::createOS($dist, '/opt', volume::translatePath($diskpath));
                    echo "Registering Image.\n";
                    $vol = volume::getByPath($diskpath);
                    $date = new DateTime();
                    $img = image::createFromVolume($vol, null, 'eZ Cluster ' . strtoupper($dist) . ' (' . $date->format("Y-m-d-Hi-s") . ") ");
                    echo "Detach Volumne $volume->id \n";
                    $vol->detach();
                    sleep(5);
                    echo "Remove Volumne $volume->id \n";
                    $vol->delete();
                    // TODO implement a test case here
                    // cho "Lauching Test AMI " . $img->id . "\n";
                    // $img->launch( "Test " . $img->id );
                    break;
                case 'init':
                    xrowAWSSDK::init(true);
                    
                    break;
                case 'permission':
                    $imageID = $args[1];
                    $img = new image((string) $imageID);
                    foreach (image::$accounts as $user) {
                        $img->addPermission($user);
                    }
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
                    
                    if ($args[1]) {
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
