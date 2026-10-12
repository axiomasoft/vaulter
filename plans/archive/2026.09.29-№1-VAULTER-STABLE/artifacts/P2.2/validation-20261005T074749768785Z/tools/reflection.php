<?php
require getcwd().'/vendor/autoload.php';
$base=$argv[1];
$map=json_decode(file_get_contents($base.'/rename-map.json'),true,512,JSON_THROW_ON_ERROR);
foreach($map as $old=>$new){
 if(class_exists($old)||interface_exists($old)||enum_exists($old))throw new RuntimeException('Obsolete class autoloads: '.$old);
 $r=new ReflectionClass($new);
 $expected=getcwd().'/packages/documents/src/'.str_replace('\\','/',substr($new,strlen('Vaulter\\Documents\\'))).'.php';
 if(realpath($r->getFileName())!==realpath($expected))throw new RuntimeException('Wrong actual source: '.$new);
 echo $new.' source='.$r->getFileName().PHP_EOL;
}
if(class_exists('Vaulter\\Documents\\Collaboration\\ReverbNodeCollaborationDriver'))throw new RuntimeException('Removed Reverb class autoloads');
foreach(['BindingsRegistrar','HttpRegistrar','CollaborationRegistrar'] as $name){$class='Vaulter\\Documents\\Providers\\'.$name;$r=new ReflectionClass($class);if(realpath($r->getFileName())!==realpath(getcwd().'/packages/documents/src/Providers/'.$name.'.php'))throw new RuntimeException('Registrar source mismatch');}
echo 'All renamed classes and registrars load from current sources; removed classes absent'.PHP_EOL;
