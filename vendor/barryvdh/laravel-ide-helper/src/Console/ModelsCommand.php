<?php
/**
 * Laravel IDE Helper Generator
 *
 * @author    Barry vd. Heuvel <barryvdh@gmail.com>
 * @copyright 2014 Barry vd. Heuvel / Fruitcake Studio (http://www.fruitcakestudio.nl)
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @link      https://github.com/barryvdh/laravel-ide-helper
 */
namespace Barryvdh\LaravelIdeHelper\Console;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\ClassLoader\ClassMapGenerator;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Context;
use phpDocumentor\Reflection\DocBlock\Tag;
use phpDocumentor\Reflection\DocBlock\Serializer as DocBlockSerializer;
/**
 * A command to generate autocomplete information for your IDE
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */
class ModelsCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'ide-helper:models
                            {models? : comma separated names of models to include}
                            {--dir="" : the model dir}
                            {--filename=_ide_helper_models.php : the path to the helper file}
                            {--ignore="" : comma separated names of models to ignore}
                            {--nowrite : don\'t write to model files}
                            {--reset : replace the existing PHPDocs}
                            {--write : write to model files}';
    /**
     * @var Filesystem $files
     */
    protected $files;
    protected $filename = '_ide_helper_models.php';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate auto completion for models';
    protected $properties = array();
    protected $methods = array();
    protected $write = false;
    protected $dirs = array();
    protected $reset;
    /**
     * @param Filesystem $files
     */
    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }
    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $filename = $this->option('filename');
        $this->write = $this->option('write');
        $this->dirs = array_merge(
            $this->laravel['config']->get('ide-helper.model_locations'),
            explode(',', $this->option('dir'))
        );
        $this->reset = $this->option('reset');
        //If filename is default and Write is not specified, ask what to do
        if (!$this->write && $filename === $this->filename && !$this->option('nowrite')) {
            $message = "Do you want to overwrite the existing model files?".
                " Choose no to write to $filename instead? (Yes/No): ";
            if ($this->confirm($message)) {
                $this->write = true;
            }
        }
        $content = $this->generateDocs();
        if (!$this->write) {
            $written = $this->files->put($filename, $content);
            if ($written !== false) {
                $this->info("Model information was written to $filename");
            } else {
                $this->error("Failed to write model information to $filename");
            }
        }
    }
    protected function getNameSpacedModelNames(&$allModels, $modelNames)
    {
        $modelClassNames = array();
        $modelNames = explode(',', '\\'. str_replace(',', ',\\', $modelNames));
        foreach ($allModels as $model) {
            if (Str::endsWith($model, $modelNames)) {
                $modelClassNames[] = $model;
            }
        }
        return $modelClassNames;
    }
    protected function generateDocs()
    {
        $hasDoctrine = interface_exists('Doctrine\DBAL\Driver');
        $allModels = $this->loadModels();
        $userModelNames = $this->argument('models');
        $output =
            "<?php
             /**
              * An helper file for your Eloquent Models
              * Copy the phpDocs from this file to the correct Model,
              * And remove them from this file, to prevent double declarations.
              *
              * @author Barry vd. Heuvel <barryvdh@gmail.com>
              */
            \n\n";
        if (empty($userModelNames)) {
            $models = &$allModels;
        } else {
            $models = $this->getNameSpacedModelNames($allModels, $userModelNames);
        }
        $ignore = $this->getNameSpacedModelNames($allModels, $this->option('ignore'));
        foreach ($models as $name) {
            if (in_array($name, $ignore)) {
                if ($this->output->getVerbosity() >= OutputStyle::VERBOSITY_VERBOSE) {
                    $this->comment("Ignoring model '$name'");
                }
                continue;
            }
            $this->properties = array();
            $this->methods = array();
            if (class_exists($name)) {
                try {
                    // handle abstract classes, interfaces, ...
                    $reflectionClass = new \ReflectionClass($name);
                    if (!$reflectionClass->isSubclassOf('Illuminate\Database\Eloquent\Model')) {
                        continue;
                    }
                    if ($this->output->getVerbosity() >= OutputStyle::VERBOSITY_VERBOSE) {
                        $this->comment("Loading model '$name'");
                    }
                    if (!$reflectionClass->isInstantiable()) {
                        throw new \Exception($name . ' is not instantiable.');
                    }
                    $model = $this->laravel->make($name);
                    if ($hasDoctrine) {
                        $this->getPropertiesFromTable($model);
                    }
                    $this->getPropertiesFromMethods($model);
                    $output .= $this->createPhpDocs($name);
                    $ignore[] = $name;
                } catch (\Exception $e) {
                    $this->error("Exception: " . $e->getMessage() . "\nCould not analyze class $name.");
                }
            }
        }
        if (!$hasDoctrine) {
            $this->error(
                'Warning: `"doctrine/dbal": "~2.3"` is required to load database information. '.
                'Please require that in your composer.json and run `composer update`.'
            );
        }
        return $output;
    }
    protected function loadModels()
    {
        $models = array();
        foreach ($this->dirs as $dir) {
            $dir = base_path() . '/' . $dir;
            if (file_exists($dir)) {
                $models += array_keys(ClassMapGenerator::createMap($dir));
            }
        }
        return $models;
    }
    /**
     * Load the properties from the database table.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function getPropertiesFromTable($model)
    {
        $table = $model->getConnection()->getTablePrefix() . $model->getTable();
        $schema = $model->getConnection()->getDoctrineSchemaManager($table);
        $databasePlatform = $schema->getDatabasePlatform();
        $databasePlatform->registerDoctrineTypeMapping('enum', 'string');
        $platformName = $databasePlatform->getName();
        $customTypes = $this->laravel['config']->get("ide-helper.custom_db_types.{$platformName}", array());
        foreach ($customTypes as $yourTypeName => $doctrineTypeName) {
            $databasePlatform->registerDoctrineTypeMapping($yourTypeName, $doctrineTypeName);
        }
        $database = null;
        if (strpos($table, '.')) {
            list($database, $table) = explode('.', $table);
        }
        $columns = $schema->listTableColumns($table, $database);
        if ($columns) {
            foreach ($columns as $column) {
                $name = $column->getName();
                if (in_array($name, $model->getDates())) {
                    $type = '\Carbon\Carbon';
                } else {
                    $type = $column->getType()->getName();
                    switch ($type) {
                        case 'string':
                        case 'text':
                        case 'date':
                        case 'time':
                        case 'guid':
                        case 'datetimetz':
                        case 'datetime':
                            $type = 'string';
                            break;
                        case 'integer':
                        case 'bigint':
                        case 'smallint':
                            $type = 'integer';
                            break;
                        case 'decimal':
                        case 'float':
                            $type = 'float';
                            break;
                        case 'boolean':
                            $type = 'boolean';
                            break;
                        default:
                            $type = 'mixed';
                            break;
                    }
                }
                $comment = $column->getComment();
                $this->setProperty($name, $type, true, true, $comment);
                // Method where<name>
                $name = Str::ucfirst(Str::studly($name));
                $modelClass = (new \ReflectionClass($model))->getName();
                $this->setMethod("where$name", "\\Illuminate\\Database\\Query\\Builder|\\$modelClass", array('$value'));
            }
        }
    }
    /**
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function getPropertiesFromMethods($model)
    {
        $methods = get_class_methods($model);
        if ($methods) {
            foreach ($methods as $method) {
                if (Str::startsWith($method, 'get') &&
                    Str::endsWith($method, 'Attribute') &&
                    $method !== 'getAttribute'
                ) {
                    //Magic get<name>Attribute
                    $name = Str::snake(substr($method, 3, -9));
                    if (!empty($name)) {
                        $this->setProperty($name, null, true, null);
                    }
                } elseif (Str::startsWith($method, 'set') &&
                    Str::endsWith($method, 'Attribute') &&
                    $method !== 'setAttribute'
                ) {
                    //Magic set<name>Attribute
                    $name = Str::snake(substr($method, 3, -9));
                    if (!empty($name)) {
                        $this->setProperty($name, null, null, true);
                    }
                } elseif (Str::startsWith($method, 'scope') && $method !== 'scopeQuery') {
                    //Magic set<name>Attribute
                    $name = Str::camel(substr($method, 5));
                    if (!empty($name)) {
                        $reflection = new \ReflectionMethod($model, $method);
                        $args = $this->getParameters($reflection);
                        //Remove the first ($query) argument
                        array_shift($args);
                        $this->setMethod($name, '\Illuminate\Database\Query\Builder|\\' . $reflection->class, $args);
                    }
                } elseif (!method_exists('Eloquent', $method) && !Str::startsWith($method, 'get')) {
                    //Use reflection to inspect the code, based on Illuminate/Support/SerializableClosure.php
                    $reflection = new \ReflectionMethod($model, $method);
                    $file = new \SplFileObject($reflection->getFileName());
                    $file->seek($reflection->getStartLine() - 1);
                    $code = '';
                    while ($file->key() < $reflection->getEndLine()) {
                        $code .= $file->current();
                        $file->next();
                    }
                    $code = trim(preg_replace('/\s\s+/', '', $code));
                    $begin = strpos($code, 'function(');
                    $code = substr($code, $begin, strrpos($code, '}') - $begin + 1);
                    foreach (array(
                                 'hasMany',
                                 'belongsToMany',
                                 'hasOne',
                                 'belongsTo',
                                 'morphTo',
                                 'morphMany',
                                 'morphToMany'
                             ) as $relation) {
                        $search = '$this->' . $relation . '(';
                        if ($pos = stripos($code, $search)) {
                            //Resolve the relation's model to a Relation object.
                            $relationObj = $model->$method();
                            if ($relationObj instanceof Relation) {
                                $relatedModel = '\\' . get_class($relationObj->getRelated());
                                if (in_array($relation, ['belongsToMany', 'hasMany', 'morphMany', 'morphToMany'])) {
                                    //Collection or array of models (because Collection is Arrayable)
                                    $this->setProperty(
                                        $method,
                                        $this->getCollectionClass($relatedModel) . '|' . $relatedModel . '[]',
                                        true,
                                        null
                                    );
                                } else {
                                    //Single model is returned
                                    $this->setProperty($method, $relatedModel, true, null);
                                }
                            }
                        }
                    }
                }
            }
        }
        // Method find()
        $this->setMethod('find', '\\'. (new \ReflectionClass($model))->getName() .'|null', array('$id'));
    }
    /**
     * @param string $name
     * @param string|null $type
     * @param bool|null $read
     * @param bool|null $write
     * @param string|null $comment
     */
    protected function setProperty($name, $type = null, $read = null, $write = null, $comment='')
    {
        if (!isset($this->properties[$name])) {
            $this->properties[$name] = array();
            $this->properties[$name]['type'] = 'mixed';
            $this->properties[$name]['read'] = false;
            $this->properties[$name]['write'] = false;
            $this->properties[$name]['comment'] = (string) $comment;
        }
        if ($type !== null) {
            $this->properties[$name]['type'] = $type;
        }
        if ($read !== null) {
            $this->properties[$name]['read'] = $read;
        }
        if ($write !== null) {
            $this->properties[$name]['write'] = $write;
        }
    }
    protected function setMethod($name, $type = '', $arguments = array())
    {
        $methods = array_change_key_case($this->methods, CASE_LOWER);
        if (!isset($methods[strtolower($name)])) {
            $this->methods[$name] = array();
            $this->methods[$name]['type'] = $type;
            $this->methods[$name]['arguments'] = $arguments;
        }
    }
    /**
     * @param string $class
     * @return string
     */
    protected function createPhpDocs($class)
    {
        $reflection = new \ReflectionClass($class);
        $namespace = $reflection->getNamespaceName();
        $className = $reflection->getShortName();
        $originalDoc = $reflection->getDocComment();
        if ($this->reset) {
            $phpdoc = new DocBlock('', new Context($namespace));
        } else {
            $phpdoc = new DocBlock($reflection, new Context($namespace));
        }
        if (!$phpdoc->getText()) {
            $phpdoc->setText($class);
        }
        $properties = array();
        $methods = array();
        foreach ($phpdoc->getTags() as $tag) {
            $name = $tag->getName();
            if ($name == "property" || $name == "property-read" || $name == "property-write") {
                $properties[] = $tag->getVariableName();
            } elseif ($name == "method") {
                $methods[] = $tag->getMethodName();
            }
        }
        foreach ($this->properties as $name => $property) {
            $name = "\$$name";
            if (in_array($name, $properties)) {
                continue;
            }
            if ($property['read'] && $property['write']) {
                $attr = 'property';
            } elseif ($property['write']) {
                $attr = 'property-write';
            } else {
                $attr = 'property-read';
            }
            $tagLine = trim("@{$attr} {$property['type']} {$name} {$property['comment']}");
            $tag = Tag::createInstance($tagLine, $phpdoc);
            $phpdoc->appendTag($tag);
        }
        foreach ($this->methods as $name => $method) {
            if (in_array($name, $methods)) {
                continue;
            }
            $arguments = implode(', ', $method['arguments']);
            $tag = Tag::createInstance("@method static {$method['type']} {$name}({$arguments})", $phpdoc);
            $phpdoc->appendTag($tag);
        }
        $serializer = new DocBlockSerializer();
        $serializer->getDocComment($phpdoc);
        $docComment = $serializer->getDocComment($phpdoc);
        if ($this->write) {
            $filename = $reflection->getFileName();
            $contents = $this->files->get($filename);
            if ($originalDoc) {
                $contents = str_replace($originalDoc, $docComment, $contents);
            } else {
                $needle = "class {$className}";
                $replace = "{$docComment}\nclass {$className}";
                $pos = strpos($contents, $needle);
                if ($pos !== false) {
                    $contents = substr_replace($contents, $replace, $pos, strlen($needle));
                }
            }
            if ($this->files->put($filename, $contents)) {
                $this->info('Written new phpDocBlock to ' . $filename);
            }
        }
        $output = "namespace {$namespace}{\n{$docComment}\n\tclass {$className} {}\n}\n\n";
        return $output;
    }
    /**
     * Get the parameters and format them correctly
     *
     * @param \ReflectionMethod $method
     * @return array
     */
    public function getParameters($method)
    {
        //Loop through the default values for parameters, and make the correct output string
        $paramsWithDefault = array();
        /** @var \ReflectionParameter $param */
        foreach ($method->getParameters() as $param) {
            $paramStr = '$' . $param->getName();
            if ($param->isOptional() && $param->isDefaultValueAvailable()) {
                $default = $param->getDefaultValue();
                if (is_bool($default)) {
                    $default = $default ? 'true' : 'false';
                } elseif (is_array($default)) {
                    $default = 'array()';
                } elseif ($default === null) {
                    $default = 'null';
                } else {
                    $default = "'" . trim($default) . "'";
                }
                $paramStr .= " = $default";
            }
            $paramsWithDefault[] = $paramStr;
        }
        return $paramsWithDefault;
    }
    /**
     * Determine a model classes' collection type.
     *
     * @see http://laravel.com/docs/eloquent-collections#custom-collections
     * @param string $className
     * @return string
     */
    private function getCollectionClass($className)
    {
        // Return something in the very very unlikely scenario the model doesn't
        // have a newCollection() method.
        if (!method_exists($className, 'newCollection')) {
            return '\Illuminate\Database\Eloquent\Collection';
        }
        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = new $className;
        return '\\' . get_class($model->newCollection());
    }
}