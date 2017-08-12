<?php
define('SPEC_PATH', dirname(__DIR__) . '/spec/');
require_once dirname(__DIR__) . '/config.core.php';
require_once MODX_CORE_PATH.'model/modx/modx.class.php';
$modx = new modX();
$modx->initialize('web');
$modx->getService('error','error.modError', '', '');

$xmlFiles = [
    MODX_CORE_PATH . 'model/schema/modx.mysql.schema.xml',
    MODX_CORE_PATH . 'model/schema/modx.registry.db.mysql.schema.xml',
    MODX_CORE_PATH . 'model/schema/modx.sources.mysql.schema.xml',
    MODX_CORE_PATH . 'model/schema/modx.transport.mysql.schema.xml',
];

if (!file_exists(SPEC_PATH . 'models/')) {
    mkdir(SPEC_PATH . 'models/');
}

$schemas = [];

foreach ($xmlFiles as $file) {
    $xml = simplexml_load_file($file);
    foreach ($xml as $object) {
        $attributes = $object->attributes(); // can have class, extends, table
        $class = (string)$attributes['class'];
        $table = isset($attributes['table']) ? (string)$attributes['table'] : '';
        if ($table === '') {
            echo "Skipping model definition for {$class} as it has no table\n";
            continue;
        }
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

        foreach ($object->field as $fld) {
            $fldAttributes = $fld->attributes();
            $property = convertPhpTypeToType((string)$fldAttributes['phptype']);

            $key = (string)$fldAttributes['key'];

            // Check for a default
            $default = (string)$fldAttributes['default'];
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

        $json = json_encode($def, JSON_PRETTY_PRINT);
        $relModelFile = 'models/' . strtolower($class) . '.json';
        $modelFile = SPEC_PATH . $relModelFile;
        if (file_exists($modelFile)) {
            unlink($modelFile);
        }

        if (file_put_contents($modelFile, $json)) {
            echo 'Wrote model definition for ' . $class . " to {$relModelFile}\n";
        }

        $schemas[$class] = $relModelFile;
    }
}
$spec = file_get_contents(SPEC_PATH . 'openapi.json');
$spec = json_decode($spec, true);
$spec['components']['schemas'] = $schemas;
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