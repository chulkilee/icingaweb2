<?php
/* Icinga Web 2 | (c) 2013-2015 Icinga Development Team | GPLv2+ */

namespace Icinga\Authentication\User;

use Countable;
use Icinga\Application\Logger;
use Icinga\Application\Icinga;
use Icinga\Data\ConfigObject;
use Icinga\Data\ResourceFactory;
use Icinga\Exception\ConfigurationError;
use Icinga\User;

/**
 * Base class for concrete user backends
 */
abstract class UserBackend implements Countable
{
    /**
     * The default user backend types provided by Icinga Web 2
     *
     * @var array
     */
    private static $defaultBackends = array( // I would have liked it if I were able to declare this as constant :'(
        'external',
        'db',
        'ldap',
        'msldap'
    );

    /**
     * The registered custom user backends with their identifier as key and class name as value
     *
     * @var array
     */
    protected static $customBackends;

    /**
     * The name of this backend
     *
     * @var string
     */
    protected $name;

    /**
     * Set this backend's name
     *
     * @param   string  $name
     *
     * @return  $this
     */
    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Return this backend's name
     *
     * @return  string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Fetch all custom user backends from all loaded modules
     */
    public static function loadCustomUserBackends()
    {
        if (static::$customBackends !== null) {
            return;
        }

        static::$customBackends = array();
        $providedBy = array();
        foreach (Icinga::app()->getModuleManager()->getLoadedModules() as $module) {
            foreach ($module->getUserBackends() as $identifier => $className) {
                if (array_key_exists($identifier, $providedBy)) {
                    Logger::warning(
                        'Cannot register UserBackend of type "%s" provided by module "%s".'
                        . ' The type is already provided by module "%s"',
                        $identifier,
                        $module->getName(),
                        $providedBy[$identifier]
                    );
                } elseif (in_array($identifier, static::$defaultBackends)) {
                    Logger::warning(
                        'Cannot register UserBackend of type "%s" provided by module "%s".'
                        . ' The type is a default type provided by Icinga Web 2',
                        $identifier,
                        $module->getName()
                    );
                } else {
                    $providedBy[$identifier] = $module->getName();
                    static::$customBackends[$identifier] = $className;
                }
            }
        }
    }

    /**
     * Validate and return the class for the given custom user backend
     *
     * @param   string  $identifier     The identifier of the custom user backend
     *
     * @return  string|null             The name of the class or null in case there was no
     *                                   backend found with the given identifier
     *
     * @throws  ConfigurationError      In case the class could not be successfully validated
     */
    protected static function getCustomUserBackend($identifier)
    {
        static::loadCustomUserBackends();
        if (array_key_exists($identifier, static::$customBackends)) {
            $className = static::$customBackends[$identifier];
            if (! class_exists($className)) {
                throw new ConfigurationError(
                    'Cannot utilize UserBackend of type "%s". Class "%s" does not exist',
                    $identifier,
                    $className
                );
            } elseif (! is_subclass_of($className, __CLASS__)) {
                throw new ConfigurationError(
                    'Cannot utilize UserBackend of type "%s". Class "%s" is not a sub-type of UserBackend',
                    $identifier,
                    $className
                );
            }

            return $className;
        }
    }

    /**
     * Create and return a UserBackend with the given name and given configuration applied to it
     *
     * @param   string          $name
     * @param   ConfigObject    $backendConfig
     *
     * @return  UserBackend
     *
     * @throws  ConfigurationError
     */
    public static function create($name, ConfigObject $backendConfig)
    {
        if ($backendConfig->name !== null) {
            $name = $backendConfig->name;
        }

        if (! ($backendType = strtolower($backendConfig->backend))) {
            throw new ConfigurationError(
                'Authentication configuration for backend "%s" is missing the \'backend\' directive',
                $name
            );
        }
        if ($backendType === 'external') {
            $backend = new ExternalBackend($backendConfig);
            $backend->setName($name);
            return $backend;
        }
        if (in_array($backendType, static::$defaultBackends)) {
            // The default backend check is the first one because of performance reasons:
            // Do not attempt to load a custom user backend unless it's actually required
        } elseif (($customClass = static::getCustomUserBackend($backendType)) !== null) {
            $backend = new $customClass($backendConfig);
            $backend->setName($name);
            return $backend;
        } else {
            throw new ConfigurationError(
                'Authentication configuration for backend "%s" defines an invalid backend type.'
                . ' Backend type "%s" is not supported',
                $name,
                $backendType
            );
        }

        if ($backendConfig->resource === null) {
            throw new ConfigurationError(
                'Authentication configuration for backend "%s" is missing the \'resource\' directive',
                $name
            );
        }
        $resource = ResourceFactory::create($backendConfig->resource);

        switch ($backendType) {
            case 'db':
                $backend = new DbUserBackend($resource);
                break;
            case 'msldap':
                $groupOptions = array(
                    'group_base_dn'             => $backendConfig->get('group_base_dn', $resource->getDN()),
                    'group_attribute'           => $backendConfig->get('group_attribute', 'sAMAccountName'),
                    'group_member_attribute'    => $backendConfig->get('group_member_attribute', 'member'),
                    'group_class'               => $backendConfig->get('group_class', 'group')
                );
                $backend = new LdapUserBackend(
                    $resource,
                    $backendConfig->get('user_class', 'user'),
                    $backendConfig->get('user_name_attribute', 'sAMAccountName'),
                    $backendConfig->get('base_dn', $resource->getDN()),
                    $backendConfig->get('filter'),
                    $groupOptions
                );
                break;
            case 'ldap':
                if ($backendConfig->user_class === null) {
                    throw new ConfigurationError(
                        'Authentication configuration for backend "%s" is missing the \'user_class\' directive',
                        $name
                    );
                }
                if ($backendConfig->user_name_attribute === null) {
                    throw new ConfigurationError(
                        'Authentication configuration for backend "%s" is'
                        . ' missing the \'user_name_attribute\' directive',
                        $name
                    );
                }
                $groupOptions = array(
                    'group_base_dn'             => $backendConfig->group_base_dn,
                    'group_attribute'           => $backendConfig->group_attribute,
                    'group_member_attribute'    => $backendConfig->group_member_attribute,
                    'group_class'               => $backendConfig->group_class
                );
                $backend = new LdapUserBackend(
                    $resource,
                    $backendConfig->user_class,
                    $backendConfig->user_name_attribute,
                    $backendConfig->get('base_dn', $resource->getDN()),
                    $backendConfig->get('filter'),
                    $groupOptions
                );
                break;
        }

        $backend->setName($name);
        return $backend;
    }

    /**
     * Authenticate the given user
     *
     * @param   User    $user
     * @param   string  $password
     *
     * @return  bool
     */
    abstract public function authenticate(User $user, $password);
}