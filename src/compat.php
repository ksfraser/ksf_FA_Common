<?php
/**
 * Backward Compatibility Aliases
 *
 * @deprecated Use ksfraser\FrontAccounting\Common\* namespace instead.
 * These aliases allow existing code using KsfCommon\* to continue working.
 * Will be removed in a future major version.
 */

// ContactType
class_alias(
    \ksfraser\FrontAccounting\Common\ContactType\ContactType::class,
    \KsfCommon\ContactType\ContactType::class
);
class_alias(
    \ksfraser\FrontAccounting\Common\ContactType\ContactTypeRegistry::class,
    \KsfCommon\ContactType\ContactTypeRegistry::class
);
class_alias(
    \ksfraser\FrontAccounting\Common\ContactType\Contract\ContactTypeProviderInterface::class,
    \KsfCommon\ContactType\Contract\ContactTypeProviderInterface::class
);

// ExtensionRegistry
class_alias(
    \ksfraser\FrontAccounting\Common\ExtensionRegistry\ExtensionRegistry::class,
    \KsfCommon\ExtensionRegistry\ExtensionRegistry::class
);
class_alias(
    \ksfraser\FrontAccounting\Common\ExtensionRegistry\ExtensionRegistryInterface::class,
    \KsfCommon\ExtensionRegistry\ExtensionRegistryInterface::class
);

// Menu
class_alias(
    \ksfraser\FrontAccounting\Common\Menu\FAModuleMenu::class,
    \KsfCommon\Menu\FAModuleMenu::class
);

// Notification
class_alias(
    \ksfraser\FrontAccounting\Common\Notification\Notification::class,
    \KsfCommon\Notification\Notification::class
);
class_alias(
    \ksfraser\FrontAccounting\Common\Notification\NotificationRepository::class,
    \KsfCommon\Notification\NotificationRepository::class
);
class_alias(
    \ksfraser\FrontAccounting\Common\Notification\NotificationService::class,
    \KsfCommon\Notification\NotificationService::class
);
class_alias(
    \ksfraser\FrontAccounting\Common\Notification\Contract\NotificationStorageInterface::class,
    \KsfCommon\Notification\Contract\NotificationStorageInterface::class
);

// Plugin
class_alias(
    \ksfraser\FrontAccounting\Common\Plugin\AbstractPlugin::class,
    \KsfCommon\Plugin\AbstractPlugin::class
);
class_alias(
    \ksfraser\FrontAccounting\Common\Plugin\PluginInterface::class,
    \KsfCommon\Plugin\PluginInterface::class
);
class_alias(
    \ksfraser\FrontAccounting\Common\Plugin\PluginRegistry::class,
    \KsfCommon\Plugin\PluginRegistry::class
);

// Preference
class_alias(
    \ksfraser\FrontAccounting\Common\Preference\PreferenceHookContract::class,
    \KsfCommon\Preference\PreferenceHookContract::class
);
class_alias(
    \ksfraser\FrontAccounting\Common\Preference\PreferenceRepository::class,
    \KsfCommon\Preference\PreferenceRepository::class
);

// Queue
class_alias(
    \ksfraser\FrontAccounting\Common\Queue\JobQueue::class,
    \KsfCommon\Queue\JobQueue::class
);

// Storage
class_alias(
    \ksfraser\FrontAccounting\Common\Storage\FileStorageService::class,
    \KsfCommon\Storage\FileStorageService::class
);
class_alias(
    \ksfraser\FrontAccounting\Common\Storage\Contract\FileStorageInterface::class,
    \KsfCommon\Storage\Contract\FileStorageInterface::class
);

// Traits
class_alias(
    \ksfraser\FrontAccounting\Common\Traits\CalendarRegistrationTrait::class,
    \KsfCommon\Traits\CalendarRegistrationTrait::class
);

// Utils
class_alias(
    \ksfraser\FrontAccounting\Common\Utils\ComposerDependencies::class,
    \KsfCommon\Utils\ComposerDependencies::class
);
class_alias(
    \ksfraser\FrontAccounting\Common\Utils\ComposerInstaller::class,
    \KsfCommon\Utils\ComposerInstaller::class
);
class_alias(
    \ksfraser\FrontAccounting\Common\Utils\SchemaInstaller::class,
    \KsfCommon\Utils\SchemaInstaller::class
);
class_alias(
    \ksfraser\FrontAccounting\Common\Utils\SchemaMigrationRunner::class,
    \KsfCommon\Utils\SchemaMigrationRunner::class
);

// BaseHooks
class_alias(
    \ksfraser\FrontAccounting\Common\BaseHooks::class,
    \KsfCommon\BaseHooks::class
);
