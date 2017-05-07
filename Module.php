<?php
namespace Folksonomy;

/*
 * Folksonomy
 *
 * Add tags and tagging form to any resource to create uncontrolled vocabularies
 * and tag clouds.
 *
 * @copyright Daniel Berthereau, 2013-2017
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */

use Folksonomy\Entity\Tagging;
use Folksonomy\Form\Config as ConfigForm;
use Omeka\Api\Representation\AbstractResourceRepresentation;
use Omeka\Api\Request;
use Omeka\Module\AbstractModule;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Mvc\Controller\AbstractController;
use Zend\Mvc\MvcEvent;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\View\Renderer\PhpRenderer;

/**
 * Folksonomy
 *
 * Add tags and tagging form to any resource to create uncontrolled vocabularies
 * and tag clouds.
 *
 * @copyright Daniel Berthereau, 2013-2017
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    /**
     * @var array Cache of tags and taggings by resource.
     */
    protected $cache = ['tags' => [], 'taggings' => []];

    /**
     * Settings and their default values.
     *
     * @var array
     */
    protected $settings = [
        'folksonomy_public_allow_tag' => true,
        'folksonomy_public_require_moderation' => false,
        'folksonomy_max_length_tag' => 190,
        'folksonomy_max_length_total' => 1000,
        'folksonomy_message' => '+',
        'folksonomy_legal_text' => '',
    ];

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);
        $this->addAclRules();
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $t = $serviceLocator->get('MvcTranslator');

        $sql = <<<'SQL'
CREATE TABLE `tag` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_389B7835E237E06` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tagging` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `status` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tag_id` int(11) DEFAULT NULL,
  `resource_id` int(11) DEFAULT NULL,
  `owner_id` int(11) DEFAULT NULL,
  `created` datetime NOT NULL,
  `modified` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `owner_tag_resource` (`owner_id`,`tag_id`,`resource_id`),
  KEY `IDX_A4AED1237B00651C` (`status`),
  KEY `IDX_A4AED123BAD26311` (`tag_id`),
  KEY `IDX_A4AED12389329D25` (`resource_id`),
  KEY `IDX_A4AED1237E3C61F9` (`owner_id`),
  CONSTRAINT `FK_A4AED1237E3C61F9` FOREIGN KEY (`owner_id`) REFERENCES `user` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_A4AED12389329D25` FOREIGN KEY (`resource_id`) REFERENCES `resource` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_A4AED123BAD26311` FOREIGN KEY (`tag_id`) REFERENCES `tag` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
        $conn = $serviceLocator->get('Omeka\Connection');
        $conn->exec($sql);

        $html = '<p>';
        $html .= $t->translate(sprintf('I agree with %sterms of use%s and I accept to free my contribution under the licence %sCC BY-SA%s.', // @translate
            '<a rel="licence" href="#" target="_blank">', '</a>',
            '<a rel="licence" href="https://creativecommons.org/licenses/by-sa/3.0/" target="_blank">', '</a>'
        ));
        $html .= '</p>';
        $this->settings['folksonomy_legal_text'] = $html;

        $settings = $serviceLocator->get('Omeka\Settings');
        foreach ($this->settings as $name => $value) {
            $settings->set($name, $value);
        }
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $sql = <<<'SQL'
DROP TABLE IF EXISTS tagging;
DROP TABLE IF EXISTS tag;
SQL;
        $conn = $serviceLocator->get('Omeka\Connection');
        $conn->exec($sql);

        $settings = $serviceLocator->get('Omeka\Settings');
        foreach ($this->settings as $name => $value) {
            $settings->delete($name);
        }
    }

    /**
     * Add ACL rules for this module.
     */
    protected function addAclRules()
    {
        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');
        $settings = $services->get('Omeka\Settings');

        $publicAllowTag = $settings->get('folksonomy_public_allow_tag', $this->settings['folksonomy_public_allow_tag']);
        $publicEntityRights = ['read'];
        $publicAdapterRights = ['search', 'read'];
        if ($publicAllowTag) {
            $publicEntityRights[] = 'create';
            $publicAdapterRights[] = 'create';
        }

        // Similar than items or item sets from Omeka\Service\AclFactory.
        $acl->allow(
            null,
            [
                'Folksonomy\Controller\Admin\Tagging',
                'Folksonomy\Controller\Site\Tagging',
                'Folksonomy\Controller\Tagging',
            ]
        );
        $acl->allow(
            null,
            'Folksonomy\Api\Adapter\TaggingAdapter',
            $publicAdapterRights
        );
        $acl->allow(
            null,
            'Folksonomy\Entity\Tagging',
            $publicEntityRights
        );

        $acl->allow(
            'researcher',
            'Folksonomy\Controller\Admin\Tagging',
            [
                'add',
                'index',
                'search',
                'browse',
                'show',
                'show-details',
                'sidebar-select',
            ]
        );
        $acl->allow(
            'researcher',
            'Folksonomy\Api\Adapter\TaggingAdapter',
            [
                'create',
            ]
        );
        $acl->allow(
            'researcher',
            'Folksonomy\Entity\Tagging',
            [
                'create',
            ]
        );

        $acl->allow(
            'author',
            'Folksonomy\Controller\Admin\Tagging',
            [
                'add',
                'index',
                'search',
                'browse',
                'show',
                'show-details',
                'sidebar-select',
            ]
        );
        $acl->allow(
            'author',
            'Folksonomy\Api\Adapter\TaggingAdapter',
            [
                'create',
            ]
        );
        $acl->allow(
            'author',
            'Folksonomy\Entity\Tagging',
            [
                'create',
            ]
        );

        $acl->allow(
            'reviewer',
            'Folksonomy\Controller\Admin\Tagging',
            [
                'add',
                'edit',
                'delete',
                'index',
                'search',
                'browse',
                'show',
                'show-details',
                'sidebar-select',
            ]
        );
        $acl->allow(
            'reviewer',
            'Folksonomy\Api\Adapter\TaggingAdapter',
            [
                'create',
                'update',
                'delete',
            ]
        );
        $acl->allow(
            'reviewer',
            'Folksonomy\Entity\Tagging',
            [
                'create',
                'update',
                'delete',
            ]
        );

        $acl->allow(
            'editor',
            'Folksonomy\Controller\Admin\Tagging',
            [
                'add',
                'edit',
                'delete',
                'index',
                'search',
                'browse',
                'show',
                'show-details',
                'sidebar-select',
            ]
        );
        $acl->allow(
            'editor',
            'Folksonomy\Api\Adapter\TaggingAdapter',
            [
                'create',
                'update',
                'delete',
            ]
        );
        $acl->allow(
            'editor',
            'Folksonomy\Entity\Tagging',
            [
                'create',
                'update',
                'delete',
            ]
        );

        // Similar than items or item sets from Omeka\Service\AclFactory.
        $acl->allow(
            null,
            [
                'Folksonomy\Controller\Admin\Tag',
                'Folksonomy\Controller\Site\Tag',
                'Folksonomy\Controller\Tag',
            ]
        );
        $acl->allow(
            null,
            'Folksonomy\Api\Adapter\TagAdapter',
            $publicAdapterRights
        );
        $acl->allow(
            null,
            'Folksonomy\Entity\Tag',
            $publicEntityRights
        );

        $acl->allow(
            'researcher',
            'Folksonomy\Controller\Admin\Tag',
            [
                'add',
                'index',
                'search',
                'browse',
                'show',
                'show-details',
                'sidebar-select',
            ]
        );
        $acl->allow(
            'researcher',
            'Folksonomy\Api\Adapter\TagAdapter',
            [
                'create',
            ]
        );
        $acl->allow(
            'researcher',
            'Folksonomy\Entity\Tag',
            [
                'create',
            ]
        );

        $acl->allow(
            'author',
            'Folksonomy\Controller\Admin\Tag',
            [
                'add',
                'index',
                'search',
                'browse',
                'show',
                'show-details',
                'sidebar-select',
            ]
        );
        $acl->allow(
            'author',
            'Folksonomy\Api\Adapter\TagAdapter',
            [
                'create',
            ]
        );
        $acl->allow(
            'author',
            'Folksonomy\Entity\Tag',
            [
                'create',
            ]
        );

        $acl->allow(
            'reviewer',
            'Folksonomy\Controller\Admin\Tag',
            [
                'add',
                'edit',
                'delete',
                'index',
                'search',
                'browse',
                'show',
                'show-details',
                'sidebar-select',
            ]
        );
        $acl->allow(
            'reviewer',
            'Folksonomy\Api\Adapter\TagAdapter',
            [
                'create',
                'update',
                'delete',
            ]
        );
        $acl->allow(
            'reviewer',
            'Folksonomy\Entity\Tag',
            [
                'create',
                'update',
                'delete',
            ]
        );

        $acl->allow(
            'editor',
            'Folksonomy\Controller\Admin\Tag',
            [
                'add',
                'edit',
                'delete',
                'index',
                'search',
                'browse',
                'show',
                'show-details',
                'sidebar-select',
            ]
        );
        $acl->allow(
            'editor',
            'Folksonomy\Api\Adapter\TagAdapter',
            [
                'create',
                'update',
                'delete',
            ]
        );
        $acl->allow(
            'editor',
            'Folksonomy\Entity\Tag',
            [
                'create',
                'update',
                'delete',
            ]
        );
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // Add the Folksonomy term definition.
        $sharedEventManager->attach(
            '*',
            'api.context',
            function (Event $event) {
                $context = $event->getParam('context');
                $context['o-module-folksonomy'] = 'http://omeka.org/s/vocabs/module/folksonomy#';
                $event->setParam('context', $context);
            }
        );

        $sharedEventManager->attach(
            'Omeka\Api\Representation\ItemRepresentation',
            'rep.resource.json',
            [$this, 'filterResourceJsonLd']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Representation\ItemSetRepresentation',
            'rep.resource.json',
            [$this, 'filterResourceJsonLd']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Representation\MediaRepresentation',
            'rep.resource.json',
            [$this, 'filterResourceJsonLd']
        );

        // Add the tagging and tag filters to resource search.
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.search.query',
            [$this, 'searchQuery']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemSetAdapter',
            'api.search.query',
            [$this, 'searchQuery']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.search.query',
            [$this, 'searchQuery']
        );

        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.search.post',
            [$this, 'cacheResourceTaggingData']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.read.post',
            [$this, 'cacheResourceTaggingData']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemSetAdapter',
            'api.search.post',
            [$this, 'cacheResourceTaggingData']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemSetAdapter',
            'api.read.post',
            [$this, 'cacheResourceTaggingData']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.search.post',
            [$this, 'cacheResourceTaggingData']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.read.post',
            [$this, 'cacheResourceTaggingData']
        );

        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.hydrate.post',
            [$this, 'handleTagging']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemSetAdapter',
            'api.hydrate.post',
            [$this, 'handleTagging']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.hydrate.post',
            [$this, 'handleTagging']
        );

        // Add the tab tagging form to the resource add and edit admin pages.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.add.section_nav',
            [$this, 'addTaggingTab']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.edit.section_nav',
            [$this, 'addTaggingTab']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.add.form.after',
            [$this, 'displayTaggingForm']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.edit.form.after',
            [$this, 'displayTaggingForm']
        );

        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.add.section_nav',
            [$this, 'addTaggingTab']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.edit.section_nav',
            [$this, 'addTaggingTab']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.add.form.after',
            [$this, 'displayTaggingForm']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.edit.form.after',
            [$this, 'displayTaggingForm']
        );

        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.add.section_nav',
            [$this, 'addTaggingTab']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.edit.section_nav',
            [$this, 'addTaggingTab']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.add.form.after',
            [$this, 'displayTaggingForm']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.edit.form.after',
            [$this, 'displayTaggingForm']
        );

        // Add the show tags to the resource show admin pages.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.show.section_nav',
            [$this, 'addTaggingTab']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.show.after',
            [$this, 'displayViewResourceTags']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.show.section_nav',
            [$this, 'addTaggingTab']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.show.after',
            [$this, 'displayViewResourceTags']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.show.section_nav',
            [$this, 'addTaggingTab']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.show.after',
            [$this, 'displayViewResourceTags']
        );

        // Add the show tags to the resource browse admin pages.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.details',
            [$this, 'displayViewEntityTags']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.details',
            [$this, 'displayViewEntityTags']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.details',
            [$this, 'displayViewEntityTags']
        );

        // Add the tags to the resource show public pages.
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.show.after',
            [$this, 'displayViewResourceTagsPublic']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Site\ItemSet',
            'view.show.after',
            [$this, 'displayViewResourceTagsPublic']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Media',
            'view.show.after',
            [$this, 'displayViewResourceTagsPublic']
        );

        // Add the tagging form to the resource show public pages.
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.show.after',
            [$this, 'displayTaggingFormPublic']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Site\ItemSet',
            'view.show.after',
            [$this, 'displayTaggingFormPublic']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Media',
            'view.show.after',
            [$this, 'displayTaggingFormPublic']
        );

        // Add the tag field to the site's browse page.
        $sharedEventManager->attach(
            'Folksonomy\Controller\Site\Index',
            'view.advanced_search',
            function (Event $event) {
                echo $event->getTarget()->partial('folksonomy/common/advanced-search.phtml');
            }
        );
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $formElementManager = $services->get('FormElementManager');

        $data = [];
        foreach ($this->settings as $name => $value) {
            $data[$name] = $settings->get($name);
        }
        $formData = [];
        $formData['folksonomy_tagging_form'] = [
            'folksonomy_message' => $data['folksonomy_message'],
            'folksonomy_max_length_tag' => $data['folksonomy_max_length_tag'],
            'folksonomy_max_length_total' => $data['folksonomy_max_length_total'],
        ];
        $formData['folksonomy_public_rights'] = [
            'folksonomy_legal_text' => $data['folksonomy_legal_text'],
            'folksonomy_public_allow_tag' => $data['folksonomy_public_allow_tag'],
            'folksonomy_public_require_moderation' => $data['folksonomy_public_require_moderation'],
        ];

        $form = $formElementManager->get(ConfigForm::class);
        $form->init();
        $form->setData($formData);

        // Allow to display fieldsets in config form.
        $vars = [];
        $vars['form'] = $form;
        return $renderer->render('folksonomy/module/config.phtml', $vars);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $params = $controller->getRequest()->getPost();

        // TODO Check ckeditor.
        // $form = new ConfigForm;
        // $form->init();
        // $form->setData($params);
        // if (!$form->isValid()) {
        //     $controller->messenger()->addErrors($form->getMessages());
        //     return false;
        // }

        // $params = $form->getData();

        // Manage fieldsets of params automatically (only used for the view).
        foreach ($params as $name => $value) {
            if (isset($this->settings[$name])) {
                $settings->set($name, $value);
            } elseif (is_array($value)) {
                foreach ($value as $subname => $subvalue) {
                    if (isset($this->settings[$subname])) {
                        $settings->set($subname, $subvalue);
                    }
                }
            }
        }
    }

    /**
     * Add the taggings data to the resource JSON-LD.
     *
     * Event $event
     */
    public function filterResourceJsonLd(Event $event)
    {
        $resource = $event->getTarget();
        $jsonLd = $event->getParam('jsonLd');
        if (isset($this->cache['tags'][$resource->id()])) {
            $jsonLd['o-module-folksonomy:tag'] = array_values($this->cache['tags'][$resource->id()]);
        }
        if (isset($this->cache['taggings'][$resource->id()])) {
            $jsonLd['o-module-folksonomy:tagging'] = array_values($this->cache['taggings'][$resource->id()]);
        }
        $event->setParam('jsonLd', $jsonLd);
    }

    /**
     * Add the tagging tab to section navigations.
     *
     * Event $event
     */
    public function addTaggingTab(Event $event)
    {
        $sectionNav = $event->getParam('section_nav');
        $sectionNav['tags'] = 'Tags'; // @translate
        $event->setParam('section_nav', $sectionNav);
    }

    /**
     * Display the tagging form.
     *
     * Event $event
     */
    public function displayTaggingForm(Event $event)
    {
        echo $event->getTarget()->partial('folksonomy/admin/tagging-form.phtml');
    }

    /**
     * Display the tagging form for public.
     *
     * Event $event
     */
    public function displayTaggingFormPublic(Event $event)
    {
        echo $event->getTarget()->partial('folksonomy/site/tagging-form.phtml');
    }

    /**
     * Display the tags for a resource.
     *
     * Event $event
     */
    public function displayViewResourceTags(Event $event)
    {
        $resource = $event->getTarget()->resource;
        $this->displayResourceTags($event, $resource);
    }

    /**
     * Display the tags for a resource.
     *
     * Event $event
     */
    public function displayViewResourceTagsPublic(Event $event)
    {
        $resource = $event->getTarget()->resource;
        $tags = $this->listResourceTags($resource);
        echo $event->getTarget()->partial(
            'folksonomy/site/tags-resource-list.phtml',
            [
                'resource' => $resource,
                'tags' => $tags,
            ]
        );
    }

    /**
     * Display the tags for a resource.
     *
     * Event $event
     */
    public function displayViewEntityTags(Event $event)
    {
        $resource = $event->getParam('entity');
        $this->displayResourceTags($event, $resource);
    }

    /**
     * Helper to display the tags for a resource.
     *
     * @param Event $event
     * @param AbstractResourceRepresentation $resource
     */
    protected function displayResourceTags(Event $event, AbstractResourceRepresentation $resource)
    {
        // Don't render the resource tags if there is no tags.
        $resourceJson = $resource->jsonSerialize();
        if (empty($resourceJson['o-module-folksonomy:tagging'])) {
            return;
        }

        echo $event->getTarget()->partial(
            'folksonomy/admin/tags-resource.phtml',
            [
                'resource' => $resource,
                'tags' => $resourceJson['o-module-folksonomy:tagging'],
            ]
        );
    }

    /**
     * Helper to filter search queries.
     *
     * @internal The queries are optimized for big bases. See "Tagging and Folksonomy"
     * of Jay Pipes
     *
     * @param Event $event
     */
    public function searchQuery(Event $event)
    {
        // TODO Add option for context (admin/public) tagging status (all or allowed/approved).

        $query = $event->getParam('request')->getContent();
        if (array_key_exists('has_tags', $query)) {
            $qb = $event->getParam('queryBuilder');
            $adapter = $event->getTarget();
            $taggingAlias = $adapter->createAlias();
            $resourceAlias = $adapter->getEntityClass();
            $qb->innerJoin(
                'Folksonomy\Entity\Tagging',
                $taggingAlias,
                'WITH',
                $qb->expr()->andX(
                    $qb->expr()->eq($taggingAlias . '.resource', $resourceAlias . '.id'),
                    $qb->expr()->isNotNull($taggingAlias . '.tag')
                )
            );
        }

        if (array_key_exists('tag', $query)) {
            $tags = is_array($query['tag']) ? $query['tag'] : [$query['tag']];
            $qb = $event->getParam('queryBuilder');
            $adapter = $event->getTarget();
            $resourceAlias = $adapter->getEntityClass();
            // All resources with any tag ("OR").
            // TODO The resquest is working, but it needs a format for querying.
            /*
            $qb
                ->innerJoin(
                    'Folksonomy\Entity\Tagging',
                    $taggingAlias,
                    'WITH',
                    $qb->expr()->eq( $taggingAlias . '.resource', $resourceAlias . '.id')
                )
                ->innerJoin(
                    'Folksonomy\Entity\Tag',
                    $tagAlias,
                    'WITH',
                    $qb->expr()->eq($tagAlias . '.id', $taggingAlias . '.tag')
                )
                ->andWhere($qb->expr()->in($tagAlias . '.name', $tags));
            */
            // All resources with all tag ("AND").
            foreach ($tags as $key => $tag) {
                $tagAlias = $adapter->createAlias();
                $taggingAlias = $adapter->createAlias();
                $qb
                    // Simulate a cross join, not managed by doctrine.
                    ->innerJoin(
                        'Folksonomy\Entity\Tag', $tagAlias,
                        'WITH', '1 = 1'
                    )
                    ->innerJoin(
                        'Folksonomy\Entity\Tagging',
                        $taggingAlias,
                        'WITH',
                        $qb->expr()->andX(
                            $qb->expr()->eq($taggingAlias . '.resource', $resourceAlias . '.id'),
                            $qb->expr()->eq($taggingAlias . '.tag', $tagAlias . '.id')
                        )
                    )
                    ->andWhere($qb->expr()->eq(
                        $tagAlias . '.name',
                        $adapter->createNamedParameter($qb, $tag)
                    ));
            }
        }
    }

    /**
     * Cache taggings and tags for resource API search/read.
     *
     * @internal The cache avoids self::filterItemJsonLd() to make multiple
     * queries to the database during one request.
     *
     * @param Event $event
     */
    public function cacheResourceTaggingData(Event $event)
    {
        $content = $event->getParam('response')->getContent();
        // Check if this is an api search or api read.
        $resourceIds = [];
        if (is_array($content)) {
            foreach ($content as $resource) {
                $resourceIds[] = $resource->getId();
            }
        } else {
            $resourceIds[] = $content->getId();
        }
        if (empty($resourceIds)) {
            return;
        }

        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $taggings = $api
            ->search('taggings', ['resource_id' => $resourceIds])
            ->getContent();
        foreach ($taggings as $tagging) {
            $resourceId = $tagging->resource()->id();
            // Cache tags.
            if (!is_null($tagging->tag())) {
                $this->cache['tags'][$resourceId][$tagging->tag()->id()] = $tagging->tag();
            }
            // Cache taggings.
            $this->cache['taggings'][$resourceId][$tagging->id()] = $tagging;
        }
    }

    /**
     * Handle hydration for tagging data.
     *
     * @param Event $event
     */
    public function handleTagging(Event $event)
    {
        $resourceAdapter = $event->getTarget();
        $request = $event->getParam('request');

        if (!$resourceAdapter->shouldHydrate($request, 'o-module-folksonomy:tagging')) {
            return;
        }

        $taggingAdapter = $resourceAdapter->getAdapter('taggings');
        $taggingData = $request->getValue('o-module-folksonomy:tagging', []);

        $taggingId = null;
        $tags = null;

        if (isset($taggingData['o:id']) && is_numeric($taggingData['o:id'])) {
            $taggingId = $taggingData['o:id'];
        }
        if (isset($taggingData['o-module-folksonomy:tag'])
            && '' !== trim($taggingData['o-module-folksonomy:tag'])
        ) {
            $tags = $taggingData['o-module-folksonomy:tag'];
        }

        if (null === $tags) {
            // This request has no tagging data. If a tagging for this resource
            // exists, delete it. If no tagging for this resource exists, do nothing.
            if (null !== $taggingId) {
                // Delete tagging
                $subRequest = new \Omeka\Api\Request('delete', 'taggings');
                $subRequest->setId($taggingId);
                $taggingsAdapter->deleteEntity($subRequest);
            }
        } else {
            // This request has tagging data. If a tagging for this resource exists,
            // update it. If no tagging for this resource exists, create it.
            if ($taggingId) {
                // Update tagging
                $subRequest = new \Omeka\Api\Request('update', 'taggings');
                $subRequest->setId($taggingData['o:id']);
                $subRequest->setContent($taggingData);
                $tagging = $taggingsAdapter->findEntity($taggingData['o:id'], $subRequest);
                $taggingsAdapter->hydrateEntity($subRequest, $tagging, new \Omeka\Stdlib\ErrorStore);
            } else {
                // Create tagging
                $subRequest = new \Omeka\Api\Request('create', 'taggings');
                $subRequest->setContent($taggingData);
                $tagging = new \Folksonomy\Entity\Tagging;
                $tagging->setItem($event->getParam('entity'));
                $taggingsAdapter->hydrateEntity($subRequest, $tagging, new \Omeka\Stdlib\ErrorStore);
                $taggingsAdapter->getEntityManager()->persist($tagging);
            }
        }
    }
}
