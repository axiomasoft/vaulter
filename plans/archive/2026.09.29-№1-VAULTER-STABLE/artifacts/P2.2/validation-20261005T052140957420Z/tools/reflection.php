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
