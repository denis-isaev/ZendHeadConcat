<?php

require_once 'Zend/View/Helper/HeadScript.php';

class Iden_View_Helper_ConcatenateHeadScript extends Zend_View_Helper_HeadScript
{
    /**
     * Uri to filepath map
     *
     * @var array
     */
    private $map = array();

    /**
     *  Enable or disable concatenation
     *
     * @var bool
     */
    private $enable = true;

    /**
     * Path for result files
     *
     * @var string
     */
    private $cacheDir = null;

    /**
     * Uri for result files
     *
     * @var string
     */
    private $cacheUri = null;

    /**
     * Constructor
     *
     * @throws Zend_View_Exception
     * @return Iden_View_Helper_ConcatenateHeadScript
     */
    public function __construct()
    {
        parent::__construct();

        $resources = Zend_Controller_Front::getInstance()->getParam('bootstrap')->getOption('resources');

        if (!array_key_exists('view', $resources) || !array_key_exists('concatenateHeadScript', $resources['view'])
            || !is_array($resources['view']['concatenateHeadScript']))
        {
            require_once 'Zend/View/Exception.php';
            $e = new Zend_View_Exception('Wrong configuration');
            $e->setView($this->view);
            throw $e;
        }

        foreach ($resources['view']['concatenateHeadScript'] as $configItemName => $configItemValue)
        {
            switch ($configItemName)
            {
                case 'cacheUri':
                    $this->cacheUri = rtrim($configItemValue, '/') . '/';
                    break;
                case 'cacheDir':
                    $this->cacheDir = rtrim($configItemValue, '/') . '/';
                    break;
                case 'enable':
                    $this->enable = $configItemValue;
                    break;
                case 'map':
                    if (!is_array($configItemValue))
                    {
                        require_once 'Zend/View/Exception.php';
                        $e = new Zend_View_Exception('Wrong configuration');
                        $e->setView($this->view);
                        throw $e;
                    }
                    foreach ($configItemValue as $k => $v)
                    {
                        $this->addMapItem($k, $v);
                    }
                    break;
            }
        }

        if (is_null($this->cacheDir))
        {
            require_once 'Zend/View/Exception.php';
            $e = new Zend_View_Exception('You must set directory path for result files in application config');
            $e->setView($this->view);
            throw $e;
        }

        if (is_null($this->cacheUri))
        {
            require_once 'Zend/View/Exception.php';
            $e = new Zend_View_Exception('You must set uri for result files in application config');
            $e->setView($this->view);
            throw $e;
        }

        if (count($this->map) == 0)
        {
            require_once 'Zend/View/Exception.php';
            $e = new Zend_View_Exception('You must set uri-to-filepath map in application config');
            $e->setView($this->view);
            throw $e;
        }
    }

    /**
     * @param string $uri
     * @param string $path
     * @return void
     */
    private function addMapItem($uri, $path)
    {
        $this->map[$uri] = $path;
    }

    /**
     * Helper method
     *
     * @param string $type
     * @param array $attrs
     * @throws Zend_View_Exception
     * @return string|Zend_View_Helper_HeadScript
     */
    public function concatenateHeadScript($type = 'text/javascript', $attrs = array())
    {
        /** @var $headScript Zend_View_Helper_HeadScript */
        /** @noinspection PhpUndefinedMethodInspection */
        $headScript = $this->view->headScript();

        /* Return parent Zend_View_Helper_HeadScript object if concatenation disabled */
        if (!$this->enable)
        {
            return $headScript;
        }

        /* Get realpath and modification time for requested scripts */
        foreach ($headScript as $item)
        {
            foreach($this->map as $k => $v)
            {
                /* Check if current script source prefix present in uri-to-filepath map */
                if(strpos($item->attributes['src'], $k) === 0)
                {
                    $item->filepath = $v . substr($item->attributes['src'], strlen($k));
                    $item->filepath = preg_replace('/(.*)\?.*/', '$1', $item->filepath);
                    $realpath = realpath($item->filepath);
                    if (false === $realpath)
                    {
                        throw new Zend_View_Exception('Wrong filepath: '.$item->filepath);
                    }
                    $item->filepath = $realpath;
                    $item->mtime    = filemtime($item->filepath);
                    continue;
                }
            }

            if (!property_exists($item, 'filepath') && !(array_key_exists('noConcat', $item->attributes) && $item->attributes['noConcat']))
            {
                throw new Zend_View_Exception('File not found for src: '.$item->attributes['src']);
            }
        }

        /* Generate concatenated scripts */
        $scriptTags = '';
        $tmpList = array();
        foreach ($headScript as $item)
        {
            if (array_key_exists('conditional', $item->attributes) || (isset($item->type) && $type != $item->type))
            {
                $item->attributes['noConcat'] = true;
            }

            if (array_key_exists('noConcat', $item->attributes) && $item->attributes['noConcat'])
            {
                if (count($tmpList) > 0)
                {
                    $scriptTags .= $this->makeItemFromList($tmpList, $type, $attrs);
                    $tmpList = array();
                }
                $scriptTags .= $this->makeItem($item);
            }
            else
            {
                $tmpList[] = $item;
            }
        }
        if (count($tmpList) > 0)
        {
            $scriptTags .= $this->makeItemFromList($tmpList, $type, $attrs);
        }

        return $scriptTags;
    }

    /**
     * Create item from file list
     *
     * @param array $list
     * @param string $type
     * @param array $attrs
     * @return string
     */
    private function makeItemFromList($list, $type, $attrs)
    {
        $fakeItem = new stdClass();
        $fakeItem->type = $type;
        $attrs['src'] = $this->makeFileFromList($list);
        $fakeItem->attributes = $attrs;
        return $this->makeItem($fakeItem);
    }

    /**
     * @param stdClass $item
     * @return string
     */
    private function makeItem($item)
    {
        if ($this->view)
        {
            /** @noinspection PhpUndefinedMethodInspection */
            $useCdata = $this->view->doctype()->isXhtml() ? true : false;
        }
        else
        {
            $useCdata = $this->useCdata ? true : false;
        }
        $escapeStart = ($useCdata) ? '//<![CDATA[' : '//<!--';
        $escapeEnd   = ($useCdata) ? '//]]>'       : '//-->';

        return $this->itemToString($item, null, $escapeStart, $escapeEnd);
    }

    /**
     * Generate concatenated script file
     *
     * @param array $list
     * @return string
     */
    private function makeFileFromList($list)
    {
        $str = '';
        foreach ($list as $item)
        {
            $str .= $item->filepath . $item->mtime;
        }
        $md5Filename = md5($str) . '.js';

        if (!is_file($this->cacheDir . $md5Filename))
        {
            $jsContent = '';
            foreach ($list as $item)
            {
                $jsContent .= file_get_contents($item->filepath) . "\n\n";
            }

            file_put_contents($this->cacheDir . $md5Filename, $jsContent, LOCK_EX);
        }

        return $this->cacheUri . $md5Filename;
    }
}
