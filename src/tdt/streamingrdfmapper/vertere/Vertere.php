<?php
/**
 * The Vertere mapping language parser
 *
 * @author until 2012: Rob Styles
 * @author starting 2012: Miel Vander Sande
 * @author starting 2013: Pieter Colpaert
 */

namespace tdt\streamingrdfmapper\vertere;
use \EasyRdf_Parser_Turtle;
use \EasyRdf_Graph;
use \Exception;

class Vertere extends \tdt\streamingrdfmapper\AMapper {

    private $resources, $base_uri, $lookups = array(), $null_values = array();

    private $ns = array(
        "vertere" => "http://example.com/schema/data_conversion#",
	"rdfs" => "http://www.w3.org/2000/01/rdf-schema#",
    );

    private $mapping;

    /**
     * Parses a mapping file and stores the right parameters in this class
     */
    protected function validate($mapping) {
        //parsing the mapping file seems like a first good step
        $this->mapping = new EasyRdf_Graph("#");
        $parser = new EasyRdf_Parser_Turtle();
        $parser->parse($this->mapping, $mapping, "turtle", "");
        
        // Find resource specs: check for resource triple values: this->resources is an array of Resources
        $this->resources = $this->mapping->allOfType("<" . $this->ns["vertere"] +"Resource>");
        if(empty($this->resources)) {
            throw new Exception("Unable to find any resource specs to work from");
        }

        //base_uri is the URI to be used when an empty prefix is used
        $base_uri_literal = $this->mapping->getLiteral("<#>","<" . $this->ns["vertere"] +"base_uri>");
        if(!is_object($base_uri_literal)){
            throw new Exception("No base uri is set in the #Spec of the Vertere Mapping File");
        }
        $this->base_uri = $base_uri_literal->getValue();
        
        // :null_values is a list of strings that indicate NULL in the source data
        $null_value_list = $this->mapping->getResource("<#>", '<' . $this->ns["vertere"] +'null_values>');
        
        if ($null_value_list && $null_value_list instanceof \EasyRdf_Collection) { //!! If this rdf:List is not well built, this is going to give serious problems
            while($null_value_list->valid()){
                array_push($this->null_values, $null_value_list->current()->getValue());
                $null_value_list->next();
            }
        } else {
            array_push($this->null_values, "");
        }
    }
       
    /**
     * Get a value from a record - This is needed to allow automatic trimming of the value and to lower the array number with 1, as vertere starts to count from 1
     */
    public function getRecordValue(&$record, $key) {
        //if the key doesn't exist in the record, lower the number with one and try again (we start counting from 1 in vertere)
        if (!array_key_exists($key, $record) && is_numeric($key) && array_key_exists($key -1, $record)){
            $key --;
        } else if (!array_key_exists($key, $record)){
            throw new Exception("Source column value is not valid: the value '$key' could not be found");
        }

        return trim($record[$key]);
    }

    /**
     * As we start to count from 1 in Vertere (why oh why?), we need to implement a new version of array_key_exists
     */
    public function recordHasKey($source_column, $key) {
        return array_key_exists($key, $record) || (is_numeric($key) && array_key_exists($key -1, $record));
    }

    /**
     * Maps a chunk towards an EasyRDF graph
     */
    public function map(&$chunk){
        //builds all the uris that can be built for this record according to the mapping file
        $uris = $this->createUris($chunk);
        $graph = new EasyRdf_Graph($this->base_uri);
        $this->addDefaultTypes($graph, $uris);
        $this->createRelationships($graph, $uris, $chunk);
        $this->createAttributes($graph, $uris, $chunk);
        return $graph;
    }

    /**
     * Adds all types to the graph according to the mapping file
     */
    private function addDefaultTypes(&$graph, &$uris) {
        foreach ($this->resources as $resource) {
            $types = $this->mapping->allResources($resource, "<" . $this->ns["vertere"] . "type>");
            foreach ($types as $type) {
                if (!empty($type) && isset($uris[$resource->getUri()])) {
                    $graph->addType($uris[$resource->getUri()], $type);
                }
            }
        }
    }

    /**
     * Create attributes for all the the resources in the graph
     */
    private function createAttributes(&$graph, &$uris, &$record) {
        foreach ($this->resources as $resource) {
            $attributes = $this->mapping->allResources($resource, "<" . $this->ns["vertere"] . "attribute>");
            foreach ($attributes as $attribute) {
                $this->createAttribute($graph, $uris, $record, $resource, $attribute);
            }
        }
    }

    private function createAttribute(&$graph, &$uris, &$record, $resource, $attribute) {
        if (!isset($uris[$resource])) {
            return;
        }
        $subject = $uris[$resource];
        $property = $this->mapping->getResource($attribute, "<" . $this->ns["vertere"] . "property>");
        $language = $this->mapping->getLiteral($attribute, "<" . $this->ns["vertere"] . "language>")->getValue(); //TODO: document this parameter?
        $datatype = $this->mapping->getResource($attribute, "<" . $this->ns["vertere"] . "datatype>");

        $value = $this->mapping->getLiteral($attribute, "<" . $this->ns["vertere"] . "value>");
        $source_column = $this->mapping->getLiteral($attribute, "<" . $this->ns["vertere"] . "source_column>");
        $source_columns = $this->mapping->getResource($attribute, "<" . $this->ns["vertere"] . "source_columns>");

        if ($value) {
            $source_value = $value->getValue();
        } else if ($source_column) {
            $source_value = $this->getRecordValue($record, $source_column->getValue());
        } else if ($source_columns) {
            $source_columns = $this->spec->get_list_values($source_columns);
            $glue = $this->mapping->getLiteral($attribute, "<" . $this->ns["vertere"] . "source_column_glue>");
            $filter = $this->mapping->getLiteral($attribute, "<" . $this->ns["vertere"] . "source_column_filter>");
            if (!isset($filter)) {
                // default: accept anything
                $filter = "//";
            }
            $source_values = array();
            foreach ($source_columns as $source_column) {
                $source_column = $source_column['value'];

//                $source_column--;
//                $value = $record[$source_column];
                $value = $this->getRecordValue($record, $source_column);

                if (preg_match($filter, $value) != 0 && !in_array($value, $this->null_values)) {
                    $source_values[] = $value;
                }
            }
            $source_value = implode($glue, $source_values);
        } else {
            return;
        }
        $lookup = $this->mapping->getResource($attribute, "<" . $this->ns["vertere"] . "lookup>");
        if ($lookup != null) {
            $lookup_value = $this->lookup($record, $lookup, $source_value);

            if ($lookup_value != null && $lookup_value['type'] == 'uri') {
                $graph->addLiteral($subject, $property, $lookup_value['value']);
                return;
            }
            else {
                $source_value = $lookup_value['value'];
            }
        }

        if (empty($source_value)) {
            return;
        }

        $source_value = $this->process($attribute, $source_value);
        $graph->addLiteral($subject, $property, $source_value, $language, $datatype);
    }

    private function createRelationships(&$graph, &$uris, &$record) {
        foreach ($this->resources as $resource) {
            $relationships = $this->mapping->allResources($resource, "<" . $this->ns["vertere"] . "relationship>");
            foreach ($relationships as $relationship) {
                $this->createRelationship($graph, $uris, $resource, $relationship, &$record);
            }
        }
    }

    private function createRelationship(&$graph, &$uris, $resource, $relationship, &$record) {
        $subject = null;
        if (array_key_exists($resource, $uris))
            $subject = $uris[$resource];

        $property = $this->mapping->getResource($relationship, "<" . $this->ns["vertere"] . "property>");

        $object_from = $this->mapping->getResource($relationship, "<" . $this->ns["vertere"] . "object_from>");
        $identity = $this->mapping->getResource($relationship, "<" . $this->ns["vertere"] . "identity>");
        $object = $this->mapping->getResource($relationship, "<" . $this->ns["vertere"] . "object>");
        $new_subject = $this->mapping->getResource($relationship, "<" . $this->ns["vertere"] . "subject>");

        if ($object_from) {
            //Prevents PHP warning on key not being present  
            if (isset($uris[$object_from]))
                $object = $uris[$object_from];
        } else if ($identity) {
            // we create a link in situ, from a colum value
            // TODO: this should be merged with the createUri() code
            $source_column = $this->mapping->getLiteral($identity, "<" . $this->ns["vertere"] . "source_column>");
//            $source_column--;
//            $source_value = $record[$source_column];
            $source_value = $this->getRecordValue($record, $source_column);

            if (empty($source_value)) {
                return;
            }

            //Check for lookups
            $lookup = $this->mapping->getResource($identity, "<" . $this->ns["vertere"] . "lookup>");
            if ($lookup != null) {
                $lookup_value = $this->lookup($record, $lookup, $source_value);
                if ($lookup_value != null && $lookup_value['type'] == 'uri') {
                    $uris[$resource] = $lookup_value['value'];
                    return;
                } else {
                    $source_value = $lookup_value['value'];
                }
            }

            $base_uri = $this->mapping->getLiteral($identity, "<" . $this->ns["vertere"] . "base_uri>");
            if ($base_uri === null) {
                $base_uri = $this->base_uri;
            }
            $source_value = $this->process($identity, $source_value);
            $object = "${base_uri}${source_value}";
        } else if ($new_subject) {
            $object = $subject;
            $subject = $new_subject;
        }

        if ($subject && $property && $object) {
            $graph->add_resource_triple($subject, $property, $object);
        } else {
            return;
        }
    }

    private function createUris(&$record) {
        $uris = array();
        foreach ($this->resources as $resource) {
            if (!isset($uris[$resource])) {
                $this->createUri($record, $uris, $resource);
            }
        }
        return $uris;
    }

    private function create_template_uri(&$record, $template, $vars) {
        $var_arr = array();
        foreach ($vars as $var) {
            $name = $this->mapping->getLiteral($var, "<" . $this->ns["vertere"] . "variable>");
            $source_column = $this->mapping->getLiteral($var, "<" . $this->ns["vertere"] . "source_column>");
            $value = $this->getRecordValue($record, $source_column);
            $var_arr[$name] = $value;
        }

        $processor = new \Guzzle\Parser\UriTemplate\UriTemplate();
        return $processor->expand($template, $var_arr);
    }

    private function createUri($record, &$uris, $resource, $identity = null) {
        if (!$identity) {
            $identity = $this->mapping->getResource($resource, "<" . $this->ns["vertere"] . "identity>");
        }
        $source_column = $this->mapping->getLiteral($identity, "<" . $this->ns["vertere"] . "source_column>")->getValue();
        //support for multiple source columns
        $source_columns = $this->mapping->getResource($identity, "<" . $this->ns["vertere"] . "source_columns>");
        $source_resource = $this->mapping->getResource($identity, "<" . $this->ns["vertere"] . "source_resource>");
        //Support for URI templates
        $template = $this->mapping->getLiteral($identity, "<" . $this->ns["vertere"] . "template>")->getValue();

        if ($template) {
            //Retrieve all declared variables and expand template
            //For now, only an unprocessed single column value is supported as a template variable
            //Future: support source_columns, source_resource, lookup and process as well => refactor whole method
            $vars = $this->spec->get_resource_triple_values($identity, "<" . $this->ns["vertere"] . "template_vars>");
            $uri = $this->create_template_uri($record, $template, $vars);
            $uris[$resource] = $uri;
            return;
        } else if ($source_column) {
            $source_value = $this->getRecordValue($record, $source_column);
        } else if ($source_columns) {
            $source_columns = $this->spec->get_list_values($source_columns);
            $glue = $this->mapping->getLiteral($identity, "<" . $this->ns["vertere"] . "source_column_glue>");
            $source_values = array();

            foreach ($source_columns as $source_column) {
                $source_column = $source_column['value'];
                //$source_column--;
                //Check if the decremented index exists before using its value 
                $key = is_numeric($source_column) ? $source_column - 1 : $source_column;

                if (array_key_exists($key, $record)) {
                    // if (!empty($record[$source_column])) {  // empty() is not a good idea: empty(0) == TRUE
                    if (!in_array($record[$key], $this->null_values)) {
                        //$source_values[] = $record[$source_column];
                        $source_values[] = $this->getRecordValue($record, $source_column);
                    } else {
                        $source_values = array();
                        break;
                    }
                }
            }

            $source_value = implode('', $source_values);
            if (!empty($source_value)) {
                $source_value = implode($glue, $source_values);
            }
        } else if ($source_resource) {
            if (!isset($uris[$source_resource])) {
                $this->createUri($record, $uris, $source_resource);
            }
            //Prevents PHP warning on key not being present   
            if (isset($uris[$source_resource]))
                $source_value = $uris[$source_resource];
        } else {
            return;
        }

        //Check for lookups
        $lookup = $this->mapping->getResource($identity, "<" . $this->ns["vertere"] . "lookup>");
        if ($lookup != null) {
            $lookup_value = $this->lookup($record, $lookup, $source_value);
            if ($lookup_value != null && $lookup_value['type'] == 'uri') {
                $uris[$resource] = $lookup_value['value'];
                return;
            } else {
                $source_value = $lookup_value['value'];
            }
        }

        //Decide on base_uri
        $base_uri = $this->mapping->getLiteral($identity, "<" . $this->ns["vertere"] . "base_uri>");
        if ($base_uri === null) {
            $base_uri = $this->base_uri;
        }

        //Decide if the resource should be nested (overrides the base_uri)
        $nest_under = $this->mapping->getResource($identity, "<" . $this->ns["vertere"] . "nest_under>");
        if ($nest_under != null) {
            if (!isset($uris[$nest_under])) {
                $this->createUri($record, $uris, $nest_under);
            }
            $base_uri = $uris[$nest_under];
            if (!preg_match('%[/#]$%', $base_uri)) {
                $base_uri .= '/';
            }
        }

        $container = $this->mapping->getLiteral($identity, "<" . $this->ns["vertere"] . "container>");
        if (!empty($container) && !preg_match('%[/#]$%', $container)) {
            $container .= '/';
        }

        //Prevents PHP warning on key not being present  
        if (!isset($source_value))
            $source_value = null;

        $source_value = $this->process($identity, $source_value);

        if (!empty($source_value)) {
            $uri = "${base_uri}${container}${source_value}";
            $uris[$resource] = $uri;
        } else {
            $identity = $this->mapping->getResource($resource, "<" . $this->ns["vertere"] . "alternative_identity>");
            if ($identity) {
                $this->createUri($record, $uris, $resource, $identity);
            }
        }
    }

    public function process($resource, $value) {
        $processes = $this->mapping->getResource($resource, "<" . $this->ns["vertere"] . "process>");
        if ($processes != null) {
            $process_steps = $this->spec->get_list_values($processes);
            foreach ($process_steps as $step) {
                $function = str_replace(NS_CONV, "", $step['value']);
                switch ($function) {
                    case 'normalise':
                        //$value = strtolower(str_replace(' ', '_', trim($value)));
                        // Swap out Non "Letters" with a _
                        $value = preg_replace('/[^\\pL\d]+/u', '_', $value);

                        // Trim out extra -'s
                        $value = trim($value, '-');

                        // Convert letters that we have left to the closest ASCII representation
                        $value = iconv('utf-8', 'us-ascii//TRANSLIT', $value);

                        // Make text lowercase
                        $value = strtolower($value);

                        // Strip out anything we haven't been able to convert
                        $value = preg_replace('/[^-\w]+/', '', $value);

                        break;

                    case 'trim_quotes':
                        $value = trim($value, '"');
                        break;

                    case 'flatten_utf8':
                        $value = preg_replace('/[^-\w]+/', '', iconv('UTF-8', 'ascii//TRANSLIT', $value));
                        break;

                    case 'title_case':
                        $value = ucwords($value);
                        break;

                    case 'url_encode':
                        $value = urlencode($value);
                        $value = str_replace("+", "%20", $value);
                        break;

                        /**
                         * create_url wil check whether the argument is not a url yet. 
                         * If it is, it will keep the url as is. 
                         * If it isn't, it will prepend the begining of the url, and it will url encode the value
                         */
                    case 'create_url':
                        $regex_output = $this->mapping->getLiteral($resource, "<" . $this->ns["vertere"] . "url>");
                        $regex_pattern = "/^(?!http.+)/";
                        if (preg_match($regex_pattern, $value)) {
                            $value = urlencode($value);
                            $value = str_replace("+", "%20", $value);
                            $value = preg_replace("${regex_pattern}", $regex_output, $value);
                        }
                        break;

                    case 'regex':
                        $regex_pattern = $this->mapping->getLiteral($resource, "<" . $this->ns["vertere"] . "regex_match>");
                        foreach (array('%', '/', '@', '!', '^', ',', '.', '-') as $candidate_delimeter) {
                            if (strpos($candidate_delimeter, $regex_pattern) === false) {
                                $delimeter = $candidate_delimeter;
                                break;
                            }
                        }
                        //MVS: Added this as a correction, not sure what above foreach does but breaking the regex
                        $delimeter = "/";
                        $regex_output = $this->mapping->getLiteral($resource, "<" . $this->ns["vertere"] . "regex_output>");
                        $value = preg_replace("${delimeter}${regex_pattern}${delimeter}", $regex_output, $value);
                        break;
//                    Now accesible under default
//                    case 'feet_to_metres':
//                        $value = Conversions::feet_to_metres($value);
//                        break;

                    case 'round':
                        $value = round($value);
                        break;

                    case 'substr':
                        $substr_start = $this->mapping->getLiteral($resource, "<" . $this->ns["vertere"] . "substr_start>");
                        $substr_length = $this->mapping->getLiteral($resource, "<" . $this->ns["vertere"] . "substr_length>");
                        $value = substr($value, $substr_start, $substr_length);
                        break;

                    default:
                        //When no built in function matches, a custom process function in called
                        //Made Conversion a little more flexible
                        if (method_exists("Conversions", $function))
                            //TODO: change this so that process doesn't contain any function anymore, but reads everything from the Conversions class
                            $value = \tdt\streamingrdfmapper\Conversions::$function($value);
                        else
                            throw new Exception("Unknown process requested: $function\n");
                }
            }
        }
        return $value;
    }

    public function lookup( $record, $lookup, $key) {
        if ($this->spec->get_subject_property_values($lookup, "<" . $this->ns["vertere"] . "lookup_entry>")) {
            return $this->lookup_config_entries($record, $lookup, $key);
        } else if ($this->spec->get_subject_property_values($lookup, "<" . $this->ns["vertere"] . "lookup_csv_file>")) {
            return $this->lookup_csv_file($lookup, $key);
        }
    }

    function lookup_config_entries($record, $lookup, $key) {
        if (!isset($this->lookups[$lookup])) {
            $entries = $this->spec->get_subject_property_values($lookup, "<" . $this->ns["vertere"] . "lookup_entry>");
            if (empty($entries)) {
                throw new Exception("Lookup ${lookup} had no lookup entries");
            }
            foreach ($entries as $entry) {
                //Accept lookups with several keys mapped to a single value
                $lookup_keys = $this->spec->get_subject_property_values($entry['value'], "<" . $this->ns["vertere"] . "lookup_key>");
                $lookup_column = $this->spec->get_subject_property_values($entry['value'], "<" . $this->ns["vertere"] . "lookup_column>");
                foreach ($lookup_keys as $lookup_key_array) {
                    $lookup_key = $lookup_key_array['value'];
                    if (isset($this->lookups[$lookup][$lookup_key])) {
                        throw new Exception("Lookup <${lookup}> contained a duplicate key");
                    }
                    $lookup_values = $this->spec->get_subject_property_values($entry['value'], "<" . $this->ns["vertere"] . "lookup_value>");
                    if (count($lookup_values) > 1) {
                        throw new Exception("Lookup ${lookup} has an entry ${entry['value']} that does not have exactly one lookup value assigned.");
                    }
                    if ($lookup_column){
                        $this->lookups[$lookup][$lookup_key]['value'] = $lookup_column[0]['value'];
                        $this->lookups[$lookup][$lookup_key]['type'] = true;
                    }
                    elseif ($lookup_values[0]){
                        $this->lookups[$lookup][$lookup_key]['value'] = $lookup_values[0];
                        $this->lookups[$lookup][$lookup_key]['type'] = false;
                    }
                } 
            }
        }        


        if (isset($this->lookups[$lookup]) && isset($this->lookups[$lookup][$key])) {
            if ($this->lookups[$lookup][$key]['type']){
                $column_value['value'] = $this->getRecordValue($record, $this->lookups[$lookup][$key]['value']);
                return $column_value;
            }
            elseif(!$this->lookups[$lookup][$key]['type'])
                return $this->lookups[$lookup][$key]['value'];
        }
        else {
            $return['value'] = $key;
            $return['type'] = false;
            return $return;
        }
    }

    function lookup_csv_file($lookup, $key) {

        if (isset($this->lookups[$lookup]['keys']) AND isset($this->lookups[$lookup]['keys'][$key])) {
            return $this->lookups[$lookup]['keys'][$key];
        }

        $filename = $this->mapping->getLiteral($lookup, "<" . $this->ns["vertere"] . "lookup_csv_file>");
        $key_column = $this->mapping->getLiteral($lookup, "<" . $this->ns["vertere"] . "lookup_key_column>");
        $value_column = $this->mapping->getLiteral($lookup, "<" . $this->ns["vertere"] . "lookup_value_column>");
        //retain file handle
        if (!isset($this->lookups[$lookup]['filehandle'])) {
            $this->lookups[$lookup]['filehandle'] = fopen($filename, 'r');
        }
        while ($row = fgetcsv($this->lookups[$lookup]['filehandle'])) {
            if ($row[$key_column] == $key) {
                $value = $row[$value_column];
                $this->lookups[$lookup]['keys'][$key] = $value;
                return $value;
            }
        }
        return false;
    }

}