<?php

require_once 'Zend/View/Helper/HeadLink.php';

class Iden_View_Helper_ConcatenateHeadStylesheet extends Zend_View_Helper_HeadLink
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
     * @return Iden_View_Helper_ConcatenateHeadStylesheet
     */
    public function __construct()
    {
        parent::__construct();

        $resources = Zend_Controller_Front::getInstance()->getParam('bootstrap')->getOption('resources');

        if (!array_key_exists('view', $resources) || !array_key_exists('concatenateHeadLink', $resources['view'])
            || !is_array($resources['view']['concatenateHeadLink']))
        {
            require_once 'Zend/View/Exception.php';
            $e = new Zend_View_Exception('Wrong configuration');
            $e->setView($this->view);
            throw $e;
        }

        foreach ($resources['view']['concatenateHeadLink'] as $configItemName => $configItemValue)
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
     * @param string $media
     * @param bool|string $conditionalStylesheet
     * @throws Zend_View_Exception
     * @return string
     */
    public function concatenateHeadStylesheet($media = 'screen', $conditionalStylesheet = false)
    {
        /** @var $headScript Zend_View_Helper_HeadLink */
        /** @noinspection PhpUndefinedMethodInspection */
        $headLink = $this->view->headLink();

        /* Move noConcat attribute from link tags attributes */
        foreach ($headLink as $item)
        {
            if (array_key_exists('extras', $item) && array_key_exists('noConcat', $item->extras))
            {
                $item->noConcat = $item->extras['noConcat'];
                unset ($item->extras['noConcat']);
            }
            else
            {
                if ($item->rel !== 'stylesheet' || $media != $item->media || $item->conditionalStylesheet
                    || (isset($item->extras) && count($item->extras) > 0))
                {
                    $item->noConcat = true;
                }
                else
                {
                    $item->noConcat = false;
                }
            }
        }

        /* Return parent Zend_View_Helper_HeadLink object if concatenation disabled */
        if (!$this->enable)
        {
            return $headLink;
        }

        /* Get realpath and modification time for requested css files */
        foreach ($headLink as $item)
        {
            foreach($this->map as $k => $v)
            {
                if(strpos($item->href, $k) === 0)
                {
                    $item->filepath = $v . substr($item->href, strlen($k));
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

            if (!property_exists($item, 'filepath'))
            {
                throw new Zend_View_Exception('File not found for Src: '.$item->href);
            }
        }

        /* Generate concatenated files */
        $linkTags = '';

        $tmpList = array();
        foreach ($headLink as $item)
        {
            if ($item->noConcat)
            {
                if (count($tmpList) > 0)
                {
                    $linkTags .= $this->makeItemFromList($tmpList, $media, $conditionalStylesheet);
                    $tmpList = array();
                }
                $linkTags .= $this->makeItem($item);
            }
            else
            {
                $tmpList[] = $item;
            }
        }
        if (count($tmpList) > 0)
        {
            $linkTags .= $this->makeItemFromList($tmpList, $media, $conditionalStylesheet);
        }

        return $linkTags;
    }

    /**
     * Create item from file list
     *
     * @param array $list
     * @param string $media
     * @param bool|string $conditionalStylesheet
     * @return string
     */
    private function makeItemFromList($list, $media, $conditionalStylesheet)
    {
        $fakeItem = new stdClass();
        $fakeItem->href = $this->makeFileFromList($list);
        $fakeItem->rel = 'stylesheet';
        $fakeItem->type = 'text/css';
        $fakeItem->media = $media;
        if ($conditionalStylesheet)
        {
            $fakeItem->conditionalStylesheet = $conditionalStylesheet;
        }
        return $this->makeItem($fakeItem);
    }

    /**
     * @param stdClass $item
     * @return string
     */
    private function makeItem($item)
    {
        return $this->itemToString($item);
    }


//appendStylesheet($href, $media, $conditionalStylesheet, $extras)

    /**
     * Generate concatenated css file
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
        $md5Filename = md5($str) . '.css';

        if (!is_file($this->cacheDir . $md5Filename))
        {
            $cssContent = '';
            foreach ($list as $item)
            {
                $cssContent .= file_get_contents($item->filepath) . "\n\n";
            }

            file_put_contents($this->cacheDir . $md5Filename, $cssContent, LOCK_EX);
        }

        return $this->cacheUri . $md5Filename;
    }
}
