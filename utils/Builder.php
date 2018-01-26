<?php

class Builder
{
    /**
     * @var modX
     */
    protected $modx;
    /**
     * @var array
     */
    protected $_files;

    public function __construct (modX $modx, array $xmlFiles)
    {
        $this->modx = $modx;
        $this->_files = $xmlFiles;
    }

    public function build()
    {
        $schemas = [];
        $paths = [];

        foreach ($this->_files as $file) {
            $xml = simplexml_load_file($file);
            $result = $this->processFile($xml);
            $schemas += $result['schemas'];
            $paths += $result['paths'];
        }

        // Grab the existing openapi file and decode it
        $spec = file_get_contents(SPEC_PATH . 'openapi.json');
        $spec = json_decode($spec, true);
        if (empty($spec)) {
            exit('Could not load existing openapi.json to update - perhaps the JSON is invalid?');
        }

        // Update the schemas
        $spec['components']['schemas'] = $schemas;
        $spec['paths'] = $paths;

        // Write it again
        file_put_contents(SPEC_PATH . 'openapi.json', json_encode($spec, JSON_PRETTY_PRINT));
    }

    public function processFile(SimpleXMLElement $xml)
    {
        $schemas = [];
        $paths = [];

        $rootAttribs = $xml->attributes();
        $pkg = '';
        if ((string)$rootAttribs->package !== 'modx') {
            $pkg = (string)$rootAttribs->package;
            $pkg = strpos($pkg, 'modx.') === 0 ? substr($pkg, strlen('modx.')) : $pkg;
        }

        /** @var SimpleXMLElement $object */
        foreach ($xml as $object) {
            $attributes = $object->attributes(); // can have class, extends, table
            $class = (string)$attributes['class'];
            $table = isset($attributes['table']) ? (string)$attributes['table'] : '';
            if ($table === '') {
                echo "Skipping model definition for {$class} as it has no table\n";
                continue;
            }

            // Grab the field meta through xPDO so we get all info from descendants too
            $classWithPackage = !empty($pkg) ? $pkg.'.'.$class : $class;
            $meta = $this->modx->getFieldMeta($classWithPackage);
            if (empty($meta)) {
                echo "Couldn't find field meta for $class (package $pkg), skipping\n";
                continue;
            }

            $schemas[$class] = $this->defineModel($class, $meta);

            // Auto generate the URI from the class by turning something like modTemplateVar into template/var
            $uri = $this->getUriFromClass($class, $pkg);
            $title = $this->getTitleFromClass($class);

            if (method_exists($this, 'generatePathsFor' . $class)) {
                $paths += $this->{'generatePathsFor' . $class}($uri, $class, $classWithPackage, $meta, $title);
            }
            else {
                $paths += $this->generatePaths($uri, $class, $classWithPackage, $meta, $title);
            }
        }
        return [
            'schemas' => $schemas,
            'paths' => $paths,
        ];
    }

    public function defineModel($class, $meta)
    {
        // Start preparing the OAS3 definition for the field
        $def = [];
        $def['title'] = $this->getTitleFromClass($class);
        $def['type'] = 'object';
        $def['properties'] = [];

        foreach ($meta as $key => $fld) {
            $property = $this->convertPhpTypeToType($fld['phptype']);

            // Check for a default
            $default = $fld['default'];
            if ($default !== '') {
                if ($property['type'] === 'boolean') {
                    $default = (bool)$default;
                }
                elseif ($property['type'] === 'integer') {
                    $default = (int)$default;
                }
                $property['default'] = $default;
            }

            $def['properties'][$key] = $property;
        }

        if (count($def['properties']) === 0) {
            echo "Skipping model definition for {$class} as it has no properties\n";
            return [];
        }

//        $json = json_encode($def, JSON_PRETTY_PRINT);
//        $relModelFile = 'models/' . strtolower($class) . '.json';
//        $modelFile = SPEC_PATH . $relModelFile;
//        if (file_exists($modelFile)) {
//            unlink($modelFile);
//        }
//
//        if (file_put_contents($modelFile, $json)) {
//            echo 'Wrote model definition for ' . $class . " to {$relModelFile}\n";
//        }

        return $def;
    }

    /**
     * Turns a class (e.g. modTemplateVar) into a human readable title (e.g. Template Var)
     *
     * @param string $class
     * @return array|string
     */
    public function getTitleFromClass($class)
    {
        $title = $class;
        if (strpos($class, 'mod') === 0) {
            $title = substr($class, 3);
            $title = preg_split('/(?=[A-Z])/', lcfirst($title));
            $title = array_map('ucfirst', $title);
            $title = implode(' ', $title);
        }
        return $title;
    }


    public function convertPhpTypeToType($phptype) {
        switch ($phptype) {
            case 'integer':
                return ['type' => 'integer', 'format' => 'int32'];
            case 'bool':
            case 'boolean':
                return ['type' => 'boolean'];
            default:
                return ['type' => 'string'];
        }
    }

    /**
     * @param $class
     * @param $pkg
     * @return string
     */
    public function getUriFromClass($class, $pkg)
    {
        switch ($class) {
            case 'modClassMap':
                return '/class';

            default:
                $uri = $class;
                if (strpos($class, 'mod') === 0) {
                    $uri = substr($class, 3);
                    $uri = preg_split('/(?=[A-Z])/', lcfirst($uri));
                    $uri = implode('-', $uri);
                    $uri = strtolower($uri);
                }
                $uri = '/' . $uri;

                if (!empty($pkg)) {
                    $pkg = str_replace('.', '/', $pkg);
                    $uri = '/' . $pkg . $uri;
                }
                return $uri;
        }
    }

    /**
     * @param $uri
     * @param $class
     * @param $classWithPackage
     * @param $meta
     * @param $title
     * @return array
     */
    public function generatePaths($uri, $class, $classWithPackage, $meta, $title)
    {
        $paths = [];
        $paths[$uri] = [
            'get' => $this->_standardGetCollectionRequest($class, $title),
            'post' => $this->_standardPostRequest($class, $title),
        ];

        $indices = $this->modx->getIndexMeta($classWithPackage);
        foreach ($indices as $index) {
            // Don't create a parameter for the primary
            if ($index['alias'] === 'PRIMARY') {
                continue;
            }

            if (array_key_exists($index['alias'], $meta)) {
                $paths[$uri]['get']['parameters'][] = [
                    'name' => $index['alias'],
                    'in' => 'query',
                    'description' => 'Filter on ' . $index['alias'],
                    'required' => false,
                    'schema' => $this->convertPhpTypeToType($meta[$index['alias']]['phptype']),
                ];
            }
        }
        $paths[$uri . '/{id}'] = [
            'get' => $this->_standardGetRequest($class, $title),
            'put' => $this->_standardPutRequest($class, $title),
            'delete' => $this->_standardDeleteRequest($class, $title),
        ];

        return $paths;
    }

    public function generatePathsFormodContext($uri, $class, $classWithPackage, $meta, $title)
    {
        $paths = $this->generatePaths($uri, $class, $classWithPackage, $meta, $title);
        $paths = $this->changeKey($paths, '/context/{id}', '/context/{contextKey}');
        $contextKeyParam = [
            'name' => 'contextKey',
            'in' => 'path',
            'description' => 'The key of the context.',
            'required' => true,
            'schema' => [
                'type' => 'integer'
            ]
        ];
        // Note, this overrides other parameters
        $paths['/context/{contextKey}']['get']['parameters'] = [$contextKeyParam];
        $paths['/context/{contextKey}']['put']['parameters'] = [$contextKeyParam];
        $paths['/context/{contextKey}']['delete']['parameters'] = [$contextKeyParam];
        return $paths;
    }

    public function generatePathsFormodContextSetting($uri, $class, $classWithPackage, $meta, $title)
    {
        $paths = $this->generatePaths($uri, $class, $classWithPackage, $meta, $title);
        $paths = $this->changeKey($paths, '/context-setting', '/context/{contextKey}/setting');
        $paths = $this->changeKey($paths, '/context-setting/{id}', '/context/{contextKey}/setting/{settingKey}');
        $contextKeyParam = [
            'name' => 'contextKey',
            'in' => 'path',
            'description' => 'The key of the associated context.',
            'required' => true,
            'schema' => [
                'type' => 'integer'
            ]
        ];
        $settingKeyParam = [
            'name' => 'settingKey',
            'in' => 'path',
            'description' => 'The key of the associated context.',
            'required' => true,
            'schema' => [
                'type' => 'integer'
            ]
        ];
        $paths['/context/{contextKey}/setting']['get']['parameters'][] = $contextKeyParam;
        $paths['/context/{contextKey}/setting']['post']['parameters'][] = $contextKeyParam;

        // Note, this overrides other parameters
        $paths['/context/{contextKey}/setting/{settingKey}']['get']['parameters'] = [$contextKeyParam, $settingKeyParam];
        $paths['/context/{contextKey}/setting/{settingKey}']['put']['parameters'] = [$contextKeyParam, $settingKeyParam];
        $paths['/context/{contextKey}/setting/{settingKey}']['delete']['parameters'] = [$contextKeyParam, $settingKeyParam];

        return $paths;
    }

    private function _standardGetCollectionRequest($class, $title)
    {
        return [
            'description' => 'Returns a collection of ' . $class . ' objects.',
            'tags' => [$title],
            'parameters' => [],
            'responses' => [
                '200' => [
                    'description' => 'Collection of ' . $class . ' objects.',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'array',
                                'items' => [
                                    '$ref' => '#/components/schemas/' . $class
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    private function _standardPostRequest($class, $title)
    {
        return [
            'description' => 'Creates a new ' . $class . ' object.',
            'tags' => [$title],
            'responses' => [
                '200' => [
                    'description' => 'New ' . $class . ' object successfully created.',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/' . $class
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    private function _standardGetRequest($class, $title)
    {
        return [
            'description' => 'Retrieves a single ' . $class . ' object.',
            'tags' => [$title],
            'parameters' => [
                [
                    'name' => 'id',
                    'in' => 'path',
                    'description' => 'The ID (primary key) of the ' . $class . ' object to retrieve.',
                    'required' => true,
                    'schema' => [
                        'type' => 'integer'
                    ]
                ]
            ],
            'responses' => [
                '200' => [
                    'description' => 'The requested ' . $class . ' object.',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/' . $class
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    private function _standardPutRequest($class, $title)
    {
        return [
            'description' => 'Updates a ' . $class . ' object.',
            'tags' => [$title],
            'parameters' => [
                [
                    'name' => 'id',
                    'in' => 'path',
                    'description' => 'The ID (primary key) of the ' . $class . ' object to update.',
                    'required' => true,
                    'schema' => [
                        'type' => 'integer'
                    ]
                ]
            ],
            'responses' => [
                '200' => [
                    'description' => $class . ' object updated.',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/' . $class
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    private function _standardDeleteRequest($class, $title)
    {
        return [
            'description' => 'Deletes a ' . $class . ' object.',
            'tags' => [$title],
            'parameters' => [
                [
                    'name' => 'id',
                    'in' => 'path',
                    'description' => 'The ID (primary key) of the modAccessCategory object that needs to be removed.',
                    'required' => true,
                    'schema' => [
                        'type' => 'integer'
                    ]
                ]
            ],
            'responses' => [
                '200' => [
                    'description' => $class . ' object removed.',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/' . $class
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    private function changeKey($array, $oldKey, $newKey)
    {
        if (!array_key_exists($oldKey, $array)) {
            return $array;
        }

        $keys = array_keys($array);
        $keys[array_search($oldKey, $keys, true)] = $newKey;

        return array_combine($keys, $array);
    }
}