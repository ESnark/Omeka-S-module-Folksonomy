<?php
namespace Folksonomy\Service\ViewHelper;

use Folksonomy\View\Helper\TagCount;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class TagCountFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $connection = $services->get('Omeka\Connection');
        return new TagCount($connection);
    }
}
