<?php
define('SPEC_PATH', dirname(__DIR__) . '/spec/');
require_once dirname(__DIR__) . '/config.core.php';
require_once MODX_CORE_PATH.'model/modx/modx.class.php';
$modx = new modX();
$modx->initialize('web');
$modx->getService('error','error.modError', '', '');
$modx->addPackage('modx.registry');
$modx->addPackage('modx.sources');
$modx->addPackage('modx.transport');

$xmlFiles = [
    MODX_CORE_PATH . 'model/schema/modx.mysql.schema.xml',
    MODX_CORE_PATH . 'model/schema/modx.registry.db.mysql.schema.xml',
    MODX_CORE_PATH . 'model/schema/modx.sources.mysql.schema.xml',
    MODX_CORE_PATH . 'model/schema/modx.transport.mysql.schema.xml',
];

$schemas = [];
$paths = [];

foreach ($xmlFiles as $file) {
    $xml = simplexml_load_file($file);
    $rootAttribs = $xml->attributes();
    $pkg = '';
    if ((string)$rootAttribs->package !== 'modx') {
        $pkg = (string)$rootAttribs->package;
        $pkg = strpos($pkg, 'modx.') === 0 ? substr($pkg, strlen('modx.')) : $pkg;
    }
    foreach ($xml as $object) {
        $attributes = $object->attributes(); // can have class, extends, table
        $class = (string)$attributes['class'];
        $table = isset($attributes['table']) ? (string)$attributes['table'] : '';
        if ($table === '') {
            echo "Skipping model definition for {$class} as it has no table\n";
            continue;
        }

        // Grab the field meta through xPDO so we get all info from descendants too
        $meta = $modx->getFieldMeta(!empty($pkg) ? $pkg.'.'.$class : $class);
        if (empty($meta)) {
            echo "Couldn't find field meta for $class (package $pkg), skipping\n";
            continue;
        }

        // Start preparing the OAS3 definition for the field
        $def = [];

        // Auto generate a title
        if (strpos($class, 'mod') === 0) {
            $title = substr($class, 3);
            $title = preg_split('/(?=[A-Z])/', lcfirst($title));
            $title = array_map('ucfirst', $title);
            $def['title'] = implode(' ', $title);
        }

        $def['type'] = 'object';
        $def['properties'] = [];

        foreach ($meta as $key => $fld) {
            $property = convertPhpTypeToType($fld['phptype']);

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
            continue;
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

        $schemas[$class] = $def;

        // Auto generate the URI from the class by turning something like modTemplateVar into template/var
        $uri = $class;
        if (strpos($class, 'mod') === 0) {
            $uri = substr($class, 3);
            $uri = preg_split('/(?=[A-Z])/', lcfirst($uri));
            $uri = implode('/', $uri);
            $uri = strtolower($uri);
        }
        $uri = '/' . $uri;

        if (!empty($pkg)) {
            $pkg = str_replace('.', '/', $pkg);
            $uri = '/' . $pkg . $uri;
        }

        $paths[$uri] = [
            'get' => [
                'description' => 'Returns a collection of ' . $class . ' objects.',
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
            ],
            'post' => [
                'description' => 'Creates a new ' . $class . ' object.',
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
            ]
        ];
        $paths[$uri . '/{id}'] = [
            'put' => [
                'description' => 'Updates a ' . $class . ' object.',
                'parameters' => [
                    [
                        'name' => 'id',
                        'in' => 'path',
                        'description' => 'The ID (primary key) of the modAccessCategory object to update.',
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
            ],
            'delete' => [
                'description' => 'Deletes a ' . $class . ' object.',
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
            ]
        ];
    }
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

function convertPhpTypeToType($phptype) {
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

/*


"Order": {
    "type": "object",
            "properties": {
        "id": {
            "type": "integer",
                    "format": "int64"
                },
                "petId": {
            "type": "integer",
                    "format": "int64"
                },
                "quantity": {
            "type": "integer",
                    "format": "int32"
                },
                "shipDate": {
            "type": "string",
                    "format": "date-time"
                },
                "status": {
            "type": "string",
                    "description": "Order Status",
                    "enum": [
                "placed",
                "approved",
                "delivered"
            ]
                },
                "complete": {
            "type": "boolean",
                    "default": false
                }
            },
            "xml": {
        "name": "Order"
            }
        },*/