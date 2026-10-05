<?php
require getcwd().'/vendor/autoload.php';
$classes = ['Vaulter\\Contracts\\Drive\\DriveHandle' => 'packages/core/src/Contracts/Drive/DriveHandle.php', 'Vaulter\\Drive\\DriveHandle' => 'packages/drive/src/DriveHandle.php'];
foreach ($classes as $class => $file) {
    $ref = new ReflectionClass($class);
    if (realpath($ref->getFileName()) !== realpath($file)) throw new RuntimeException('Not actual source: '.$class);
    foreach (['model'=>'Vaulter\\Models\\Drive\\Drive','profile'=>'Vaulter\\Profiles\\Profile'] as $name=>$expected) {
        $method=$ref->getMethod($name); $type=$method->getReturnType();
        if (!$method->isPublic() || $method->getNumberOfParameters() !== 0 || !$type instanceof ReflectionNamedType || $type->getName() !== $expected || $type->allowsNull()) throw new RuntimeException('Bad signature '.$class.'::'.$name);
        echo $class.'::'.$name.'(): '.$type->getName().' source='.$ref->getFileName(), PHP_EOL;
    }
    foreach (['storage','panel'] as $removed) if ($ref->hasMethod($removed)) throw new RuntimeException('Removed method present '.$class.'::'.$removed);
}
$concrete=new ReflectionClass('Vaulter\\Drive\\DriveHandle');
if (!$concrete->implementsInterface('Vaulter\\Contracts\\Drive\\DriveHandle') || !$concrete->isFinal() || !$concrete->isReadOnly()) throw new RuntimeException('Concrete contract not compiled');
foreach (['Vaulter\\Drive\\ValueObjects\\WorkspaceRef','Vaulter\\Drive\\Enums\\BulkAction','Vaulter\\Drive\\DriveContext','Vaulter\\Drive\\DriveManager','Vaulter\\Drive\\StorageManager'] as $dead) if (class_exists($dead) || interface_exists($dead) || enum_exists($dead)) throw new RuntimeException('Dead class autoloads: '.$dead);
echo 'actual source compiles, contract implemented, storage/panel and dead classes absent', PHP_EOL;

$renamePaths=json_decode(file_get_contents(getcwd().'/plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P2.2/rename-paths.json'),true,512,JSON_THROW_ON_ERROR);
foreach($renamePaths as $oldPath=>$newPath){
 $old='Vaulter\\Drive\\'.$oldPath;$new='Vaulter\\Drive\\'.$newPath;
 if(class_exists($old)||interface_exists($old)||enum_exists($old))throw new RuntimeException('Obsolete Drive class autoloads: '.$old);
 $r=new ReflectionClass($new);$expected=getcwd().'/packages/drive/src/'.str_replace('\\','/',$newPath).'.php';
 if(realpath($r->getFileName())!==realpath($expected))throw new RuntimeException('Wrong Drive source: '.$new);
}
foreach(['BindingsRegistrar','HttpRegistrar'] as $name){
 $r=new ReflectionClass('Vaulter\\Drive\\Providers\\'.$name);
 if(realpath($r->getFileName())!==realpath(getcwd().'/packages/drive/src/Providers/'.$name.'.php'))throw new RuntimeException('Drive registrar source mismatch');
}
echo 'All Drive rename-map classes and registrars load from current sources; obsolete classes absent'.PHP_EOL;
