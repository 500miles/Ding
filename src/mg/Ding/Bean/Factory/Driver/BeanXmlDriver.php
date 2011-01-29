<?php
/**
 * XML bean factory.
 *
 * PHP Version 5
 *
 * @category   Ding
 * @package    Bean
 * @subpackage Driver
 * @author     Marcelo Gornstein <marcelog@gmail.com>
 * @license    http://www.noneyet.ar/ Apache License 2.0
 * @version    SVN: $Id$
 * @link       http://www.noneyet.ar/
 */
namespace Ding\Bean\Factory\Driver;

use Ding\Bean\Lifecycle\IBeforeDefinitionListener;
use Ding\Bean\Factory\IBeanFactory;
use Ding\Bean\Factory\Exception\BeanFactoryException;
use Ding\Bean\BeanConstructorArgumentDefinition;
use Ding\Bean\BeanDefinition;
use Ding\Bean\BeanPropertyDefinition;
use Ding\Aspect\AspectDefinition;

/**
 * XML bean factory.
 *
 * PHP Version 5
 *
 * @category   Ding
 * @package    Bean
 * @subpackage Driver
 * @author     Marcelo Gornstein <marcelog@gmail.com>
 * @license    http://www.noneyet.ar/ Apache License 2.0
 * @link       http://www.noneyet.ar/
 */
class BeanXmlDriver implements IBeforeDefinitionListener
{
    /**
     * log4php logger or our own.
     * @var Logger
     */
    private $_logger;

    /**
     * beans.xml file path.
     * @var string
     */
    private $_filename;

    /**
     * SimpleXML object.
     * @var SimpleXML[]
     */
    private $_simpleXml;

    /**
     * Bean definition template to clone.
     * @var BeanDefinition
     */
    private $_templateBeanDef;

    /**
     * Bean property definition template to clone.
     * @var BeanPropertyDefinition
     */
    private $_templatePropDef;

    /**
     * Bean constructor argument definition template to clone.
     * @var BeanConstructorArgumentDefinition
     */
    private $_templateArgDef;

    /**
     * Aspect definition template to clone.
     * @var AspectDefinition
     */
    private $_templateAspectDef;

    /**
     * Current instance.
     * @var BeanFactoryXmlImpl
     */
    private static $_instance = false;

    /**
     * Gets xml errors.
     *
     * @return string
     */
    private function _getXmlErrors()
    {
        $errors = '';
        foreach (libxml_get_errors() as $error) {
            $errors .= $error->message . "\n";
        }
        return $errors;
    }

    /**
     * Initializes SimpleXML Object
     *
     * @param string $filename
     *
     * @throws BeanFactoryException
     * @return SimpleXML
     */
    private function _loadXml($filename)
    {
        if ($this->_logger->isDebugEnabled()) {
            $this->_logger->debug('Loading ' . $filename);
        }
        $xmls = array();
        libxml_use_internal_errors(true);
        if (!file_exists($filename)) {
            throw new BeanFactoryException($filename . ' not found.');
        }
        $ret = simplexml_load_string(file_get_contents($filename));
        if ($ret === false) {
            return $ret;
        }
        $xmls[$filename] = $ret;
        foreach ($ret->xpath("//import") as $imported) {
            $filename = (string)$imported->attributes()->resource;
            foreach ($this->_loadXml($filename) as $name => $xml) {
                $xmls[$name] = $xml;
            }
        }
        return $xmls;
    }

    /**
     * Returns an aspect definition.
     *
     * @param SimpleXML $simpleXmlAspect Aspect node.
     *
     * @throws BeanFactoryException
     * @return AspectDefinition
     */
    private function _loadAspect($simpleXmlAspect)
    {
        $aspects = array();
        $atts = $simpleXmlAspect->attributes();
        $aspectBean = (string)$atts->ref;
        $type = (string)$atts->type;
        if ($type == 'method') {
            $type = AspectDefinition::ASPECT_METHOD;
        } else if ($type == 'exception') {
            $type = AspectDefinition::ASPECT_EXCEPTION;
        } else {
            throw new BeanFactoryException('Invalid aspect type');
        }
        foreach ($simpleXmlAspect->pointcut as $pointcut) {
            $aspect = new AspectDefinition(
                (string)$pointcut->attributes()->expression,
                $type,
                $aspectBean
            );
        }
        return $aspect;
    }

    /**
     * Returns a property definition.
     *
     * @param SimpleXML $simpleXmlProperty Property node.
     *
     * @throws BeanFactoryException
     * @return BeanPropertyDefinition
     */
    private function _loadProperty($simpleXmlProperty)
    {
        $propName = (string)$simpleXmlProperty->attributes()->name;
        if (isset($simpleXmlProperty->ref)) {
            $propType = BeanPropertyDefinition::PROPERTY_BEAN;
            $propValue = (string)$simpleXmlProperty->ref->attributes()->bean;
        } else if (isset($simpleXmlProperty->null)) {
            $propType = BeanPropertyDefinition::PROPERTY_SIMPLE;
            $propValue = null;
        } else if (isset($simpleXmlProperty->false)) {
            $propType = BeanPropertyDefinition::PROPERTY_SIMPLE;
            $propValue = false;
        } else if (isset($simpleXmlProperty->true)) {
            $propType = BeanPropertyDefinition::PROPERTY_SIMPLE;
            $propValue = true;
        } else if (isset($simpleXmlProperty->bean)) {
            $propType = BeanPropertyDefinition::PROPERTY_BEAN;
            if (isset($simpleXmlProperty->bean->attributes()->name)) {
                $name = (string)$simpleXmlProperty->bean->attributes()->name;
            } else {
                $name = 'Bean' . microtime(true);
                $simpleXmlProperty->bean->addAttribute('id', $name);
            }
            $propValue = $name;
        } else if (isset($simpleXmlProperty->array)) {
            $propType = BeanPropertyDefinition::PROPERTY_ARRAY;
            $propValue = array();
            foreach ($simpleXmlProperty->array->entry as $arrayEntry) {
                $key = (string)$arrayEntry->attributes()->key;
                $propValue[$key] = $this->_loadProperty($arrayEntry);
            }
        } else if (isset($simpleXmlProperty->eval)) {
            $propType = BeanPropertyDefinition::PROPERTY_CODE;
            $propValue = (string)$simpleXmlProperty->eval;
        } else {
            $propType = BeanPropertyDefinition::PROPERTY_SIMPLE;
            $propValue = (string)$simpleXmlProperty->value;
        }
        return new BeanPropertyDefinition($propName, $propType, $propValue);
    }

    /**
     * Returns a constructor argument definition.
     *
     * @param SimpleXML $simpleXmlArg Argument node.
     *
     * @throws BeanFactoryException
     * @return BeanConstructorArgumentDefinition
     */
    private function _loadConstructorArg($simpleXmlArg)
    {
        if (isset($simpleXmlArg->ref)) {
            $argType = BeanConstructorArgumentDefinition::BEAN_CONSTRUCTOR_BEAN;
            $argValue = (string)$simpleXmlArg->ref->attributes()->bean;
        } else if (isset($simpleXmlArg->bean)) {
            $argType = BeanConstructorArgumentDefinition::BEAN_CONSTRUCTOR_BEAN;
            if (isset($simpleXmlArg->bean->attributes()->name)) {
                $name = (string)$simpleXmlArg->bean->attributes()->name;
            } else {
                $name = 'Bean' . microtime(true);
                $simpleXmlArg->bean->addAttribute('id', $name);
            }
            $argValue = $name;
        } else if (isset($simpleXmlArg->null)) {
            $argType = BeanConstructorArgumentDefinition::BEAN_CONSTRUCTOR_VALUE;
            $argValue = null;
        } else if (isset($simpleXmlArg->false)) {
            $argType = BeanConstructorArgumentDefinition::BEAN_CONSTRUCTOR_VALUE;
            $argValue = false;
        } else if (isset($simpleXmlArg->true)) {
            $argType = BeanConstructorArgumentDefinition::BEAN_CONSTRUCTOR_VALUE;
            $argValue = true;
        } else if (isset($simpleXmlArg->array)) {
            $argType = BeanConstructorArgumentDefinition::BEAN_CONSTRUCTOR_ARRAY;
            $argValue = array();
            foreach ($simpleXmlArg->array->entry as $arrayEntry) {
                $key = (string)$arrayEntry->attributes()->key;
                $argValue[$key] = $this->_loadConstructorArg($arrayEntry);
            }
        } else if (isset($simpleXmlArg->eval)) {
            $argType = BeanConstructorArgumentDefinition::BEAN_CONSTRUCTOR_CODE;
            $argValue = (string)$simpleXmlArg->eval;
        } else {
            $argType = BeanConstructorArgumentDefinition::BEAN_CONSTRUCTOR_VALUE;
            $argValue = (string)$simpleXmlArg->value;
        }
        return new BeanConstructorArgumentDefinition($argType, $argValue);
    }

    /**
     * Returns a bean definition.
     *
     * @param string $beanName
     *
     * @throws BeanFactoryException
     * @return BeanDefinition
     */
    private function _loadBean($beanName, BeanDefinition &$bean = null)
    {
        if (!$this->_simpleXml) {
            $this->_load();
        }
        foreach($this->_simpleXml as $name => $xml) {
            $simpleXmlBean = $xml->xpath("//bean[@id='$beanName']");
            if (!empty($simpleXmlBean)) {
                if ($this->_logger->isDebugEnabled()) {
                    $this->_logger->debug('Found ' . $beanName . ' in ' . $name);
                }
                break;
            }
        }
        if (false == $simpleXmlBean) {
            return $bean;
        }
        // asume valid xml (only one bean with that id)
        $simpleXmlBean = $simpleXmlBean[0];
        if ($bean === null) {
            $bean = clone $this->_templateBeanDef;
        }
        $bean->setName($beanName);
        $bean->setClass((string)$simpleXmlBean->attributes()->class);
        $bScope = (string)$simpleXmlBean->attributes()->scope;
        if ($bScope == 'prototype') {
            $bean->setScope(BeanDefinition::BEAN_PROTOTYPE);
        } else if ($bScope == 'singleton') {
            $bean->setScope(BeanDefinition::BEAN_SINGLETON);
        } else {
            throw new BeanFactoryException('Invalid bean scope: ' . $bScope);
        }

        if (isset($simpleXmlBean->attributes()->{'factory-method'})) {
            $bean->setFactoryMethod(
                (string)$simpleXmlBean->attributes()->{'factory-method'}
            );
        }

        if (isset($simpleXmlBean->attributes()->{'depends-on'})) {
            $bean->setDependsOn(explode(
            	',',
                (string)$simpleXmlBean->attributes()->{'depends-on'}
            ));
        }
        if (isset($simpleXmlBean->attributes()->{'factory-bean'})) {
            $bean->setFactoryBean(
                (string)$simpleXmlBean->attributes()->{'factory-bean'}
            );
        }
        if (isset($simpleXmlBean->attributes()->{'init-method'})) {
            $bean->setInitMethod(
                (string)$simpleXmlBean->attributes()->{'init-method'}
            );
        }
        if (isset($simpleXmlBean->attributes()->{'destroy-method'})) {
            $bean->setDestroyMethod(
                (string)$simpleXmlBean->attributes()->{'destroy-method'}
            );
        }
        $bProps = $bAspects = $constructorArgs = array();
        foreach ($simpleXmlBean->property as $property) {
            $bProps[] = $this->_loadProperty($property);
        }
        foreach ($simpleXmlBean->aspect as $aspect) {
            $bAspects[] = $this->_loadAspect($aspect);
        }
        foreach ($simpleXmlBean->{'constructor-arg'} as $arg) {
            $constructorArgs[] = $this->_loadConstructorArg($arg);
        }
        if (!empty($bProps)) {
            $bean->setProperties($bProps);
        }
        if (!empty($bAspects)) {
            $bean->setAspects($bAspects);
        }
        if (!empty($constructorArgs)) {
            $bean->setArguments($constructorArgs);
        }
        return $bean;
    }

    /**
     * Initialize SimpleXML.
     *
     * @throws BeanFactoryException
     * @return void
     */
    private function _load()
    {
        $this->_simpleXml = $this->_loadXml($this->_filename);
        if (empty($this->_simpleXml)) {
            throw new BeanFactoryException(
                'Could not parse: ' . $this->_filename
                . ': ' . $this->_getXmlErrors()
            );
        }
    }
    /**
     * Called from the parent class to get a bean definition.
     *
	 * @param string         $beanName Bean name to get definition for.
	 * @param BeanDefinition $bean     Where to store the data.
	 *
	 * @throws BeanFactoryException
	 * @return BeanDefinition
     */
    public function beforeDefinition(IBeanFactory $factory, $beanName, BeanDefinition &$bean = null)
    {
        return $this->_loadBean($beanName, $bean);
    }

    /**
     * Returns a instance for this driver.
     *
     * @param array $options Optional options ;)
     *
     * @return BeanXmlDriver
     */
    public static function getInstance(array $options)
    {
        if (self::$_instance == false) {
            self::$_instance = new BeanXmlDriver($options['filename']);
        }
        return self::$_instance;
    }

    /**
     * Constructor.
     *
     * @param
     *
     * @return void
     */
    protected function __construct($filename)
    {
        $this->_logger = \Logger::getLogger('Ding.Factory.Driver.BeanXmlDriver');
        $this->_beanDefs = array();
        $this->_filename = $filename;
        $this->_simpleXml = false;
        $this->_templateBeanDef = new BeanDefinition('');
        $this->_templatePropDef = new BeanPropertyDefinition('', 0, null);
        $this->_templateArgDef = new BeanConstructorArgumentDefinition(0, null);
        $this->_templateAspectDef = new AspectDefinition('', 0, '');
    }
}