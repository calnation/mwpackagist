<?php

namespace MWPackagist;

class Repository
{

    private $apiUrl = 'https://www.mediawiki.org/w/api.php';

    public function __construct($cachePath = null)
    {
        $this->cache = new \Gilbitron\Util\SimpleCache();
        $this->cache->cache_extension = '.json';
        $this->cache->cache_time = 86400;
        if (isset($cachePath)) {
            $this->cache->cache_path = $cachePath;
        }
    }

    private function convertVersion($version, $hash)
    {
        if ($version == 'master') {
            return 'dev-master';
        } else {
            $version = str_replace('REL', '', $version);
            $version = str_replace('_', '.', $version);
            return $version.'+'.$hash;
        }
    }

    private function getPackages($subset, $range, $skin = false, $force = false)
    {
        if (!$force && $this->cache->is_cached($range)) {
            $extInfoJson = $this->cache->get_cache($range);
        } else {
            $url = $this->apiUrl.
            '?action=query&format=json&list=extdistbranches';
            if ($skin) {
                $url .= '&edbskins=';
            } else {
                $url .= '&edbexts=';
            }
            $extInfoJson = $this->cache->do_curl($url.implode('|', $subset));
            $this->cache->set_cache($range, $extInfoJson);
        }
        $extInfo = json_decode($extInfoJson);
        if ($skin) {
            $type = 'skin';
        } else {
            $type = 'extension';
        }
        foreach ($subset as $plugin) {
            $composerName = 'mediawiki/'.$plugin;
            $package = array();
            if ($skin) {
                $list = $extInfo->query->extdistbranches->skins;
            } else {
                $list = $extInfo->query->extdistbranches->extensions;
            }
            if (isset($list->$plugin->source)) {
                $source = $list->$plugin->source;
            } else {
                $source = 'https://gerrit.wikimedia.org/r/p/mediawiki/'.$type.'s/'.$plugin;
            }
            foreach ($list->$plugin as $version => $url) {
                preg_match('/(REL1_[0-9][0-9]|master)-(\w+)\.tar\.gz/', $url, $versionParts);
                if (isset($versionParts[2])) {
                    $package[self::convertVersion($version, $versionParts[2])] = array(
                        'name'=>$composerName,
                        'version'=>self::convertVersion($version, $versionParts[2]),
                        'keywords'=>array('mediawiki'),
                        'dist'=>array(
                            'url'=>$url,
                            'type'=>'tar'
                        ),
                        'type'=>'mediawiki-'.$type,
                        'require'=>array(
                            'composer/installers'=>'~1.0'
                        ),
                        'homepage'=>'https://www.mediawiki.org/wiki/'.ucfirst($type).':'.$plugin,
                        'source'=>array(
                            'url'=>$source,
                            'type'=>'git',
                            'reference'=>$versionParts[2]
                        ),
                        'support'=>array(
                            'source'=>'https://phabricator.wikimedia.org/r/project/mediawiki/'.$type.'s/'.$plugin
                        )
                    );
                }
            }
            $packages[$composerName] = $package;
        }
        return $packages;
    }

    public function getJSON($force = false)
    {
        $packages = array();
        $json = json_decode(
            file_get_contents(
                $this->apiUrl.'?action=query&list=extdistrepos&format=json'
            )
        );
        $extensions = $json->query->extdistrepos->extensions;
        $skins = $json->query->extdistrepos->skins;

        for ($i = 0; $i < count($extensions); $i += 50) {
            $subset = array_slice($extensions, $i, 50);
            $range = $i.'-'.($i + count($subset) - 1);
            $packages = array_merge($packages, $this->getPackages($subset, 'extensions-'.$range, false, $force));
        }
        for ($i = 0; $i < count($skins); $i += 50) {
            $subset = array_slice($skins, $i, 50);
            $range = $i.'-'.($i + count($subset) - 1);
            $packages = array_merge($packages, $this->getPackages($subset, 'skins-'.$range, true, $force));
        }
        $json = json_encode(
            array('packages'=>$packages)
        );
        $this->cache->set_cache('extensions', $json);
        $includes = array('cache/extensions.json'=>array('sha1'=>sha1($json)));
        if (is_file(__DIR__.'/../include.json')) {
            $includes['include.json'] = array('sha1'=>sha1_file(__DIR__.'/../include.json'));
        }
        return json_encode(
            array(
                'includes'=>$includes
            )
        );
    }
}
