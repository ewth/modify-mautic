<?php

class ModifyMautic
{
    protected $dir;
    protected $db;
    protected $config;
    protected $emailsTable;
    protected $emailsStructure = [];

    /**
     * ModifyMautic constructor.
     * @throws \Exception
     */
    public function __construct()
    {

        // Load config
        $this->dir = __DIR__ . DIRECTORY_SEPARATOR;
        if (!file_exists($this->dir . 'config.php')) {
            $this->error('Config file not found or could not be read from.');
        }
        $this->config = include($this->dir . 'config.php');

        // Make sure all required config values are specified
        $expected = ['host', 'port', 'user', 'pass', 'db', 'emailsTable'];
        foreach ($expected as $item) {
            if (!isset($this->config[$item])) {
                $this->error("Config item '{$item}' missing.");
            }
        }

        // Establish database connection
        $db = new mysqli($this->config['host'], $this->config['user'], $this->config['pass'], $this->config['db'], $this->config['port']);
        if ($db->connect_error) {
            $this->error('Failed connecting to database.');
        }
        $this->db = $db;

        // Check out the emails table
        $this->emailsTable = $this->config['emailsTable'];
        if (empty($this->emailsTable)) {
            $this->error('No emails table specified in configuration.');
        }

        // Get the emails table structure for use later
        $result                = $this->db->query('DESCRIBE ' . $this->emailsTable);
        $this->emailsStructure = [];
        while ($row = $result->fetch_assoc()) {
            // We're only interested in string fields
            if (strpos($row['Type'], 'char') !== false || strpos($row['Type'], 'text') !== false) {
                $this->emailsStructure[] = $row['Field'];
            }
        }
        if (empty($this->emailsStructure)) {
            $this->error("Unable to describe table '{$this->emailsTable}'");
        }
    }

    /**
     * Throw an exception.
     *
     * @param $errorMessage
     *
     * @throws \Exception
     */
    private function error($errorMessage)
    {
        // In hindsight I probably could have just thrown an exception directly rather than use this function;
        //  but there's always that "what if I want to change it later??!" niggle in the back of the noggin.
        echo $errorMessage;
        throw new \Exception($errorMessage);
    }

    /**
     * Replace {{item}} with $email->item if it exists, within template.
     *
     * @param object $email
     * @param string $template
     *
     * @return mixed
     */
    public function parseTemplate($email, $template)
    {
        if (is_object($email) && !empty($this->emailsStructure) && is_array($this->emailsStructure)) {
            foreach ($this->emailsStructure as $key) {
                if (property_exists($email, $key)) {
                    $item = $email->{$key};
                    if (is_string($item) || empty($item)) {
                        $template = str_replace("{{{$key}}}", htmlentities(utf8_decode($item)), $template);
                    }
                }
            }
            if (isset($email->id)) {
                $template = str_replace("{{id}}", $email->id, $template);
            }
        }

        return $template;
    }

    /**
     * Parse the content fields for a template.
     *
     * @param string $content
     * @param string $template
     *
     * @return array
     */
    public function parseContentFields($content, $template)
    {
        $result = [];
        if (is_object($content)) {
            foreach ($content as $key => $item) {
                if (is_string($item)) {
                    $newTemplate = str_replace("{{contentFieldName}}", $key, $template);
                    $result[]    = str_replace("{{contentFieldValue}}", $item, $newTemplate);
                }
            }
        }

        return $result;
    }

    /**
     * Updates an email entry in the database by id.
     *
     * @param int    $id
     * @param object $email
     *
     * @return bool
     * @throws \Exception
     */
    public function updateEmail($id, $email)
    {
        if (!is_numeric($id) || $id < 1) {
            $this->error('Invalid id specified.');
        }
        $bind  = [];
        $set   = [];
        $query = "UPDATE {$this->emailsTable} SET ";

        // Load the original email
        $originalEmail = $this->getEmail($id);
        foreach ($this->emailsStructure as $item) {
            if (property_exists($originalEmail, $item) && property_exists($email, $item)) {
                // Compare the original value to the new value, only update if changed
                if ($originalEmail->{$item} != $email->{$item}) {
                    $set[]  = $item . ' = ?';
                    $bind[] = $email->{$item};
                }
            }
        }
        // Bail out if nothing to set
        if (empty($set)) {
            return true;
        }
        $query     .= implode(', ', $set);
        $query     .= ' WHERE id = ? LIMIT 1';
        $statement = $this->db->prepare($query);
        $bind[]    = $id;
        $statement->bind_param(str_repeat('s', count($bind) - 1) . 'i', ...$bind);
        $statement->execute();
        if ($statement->affected_rows) {
            return true;
        }

        return false;
    }

    /**
     * Retrieve a single email object.
     *
     * @param int $id
     *
     * @return bool|object
     * @throws \Exception
     */
    public function getEmail($id)
    {
        if (!is_numeric($id) || $id < 1) {
            $this->error('Invalid id specified.');
        }
        if ($statement = $this->db->prepare("SELECT * FROM {$this->emailsTable} WHERE id = ?")) {
            $statement->bind_param('i', $id);
            $statement->execute();
            $result = $statement->get_result();

            $email          = $result->fetch_object();
            $email->content = $this->getContentFields($email->content);

            return $email;
        }

        return false;
    }

    /**
     * Extract content fields from email
     *
     * @param string $content
     *
     * @return object
     */
    public function getContentFields($content)
    {
        $contentFields = [];
        if (!empty($content)) {
            $content = unserialize($content);
            foreach ($content as $field => $value) {
                $contentFields[$field] = $value;
            }
        }

        return (object)$contentFields;
    }

    /**
     * Convert an array of values into an email object.
     *
     * @param array $values
     *
     * @return object
     */
    public function createEmailObject($values)
    {
        $email = [];
        foreach ($this->emailsStructure as $item) {
            if ($item != 'id' && isset($values[$item])) {
                $email[$item] = $values[$item];
            }
        }
        if (!empty($values['contentField']) && is_array($values['contentField'])) {
            $content = [];
            foreach ($values['contentField'] as $key) {
                if (isset($values[$key])) {
                    $content[$key] = $values[$key];
                }
            }
            $email['content'] = serialize($content);
        }

        return (object)$email;
    }

    /**
     * Fetch all emails from the database.
     *
     * @return array
     */
    public function getEmails()
    {
        // Let's assume nobody is going to try and inject anything into their own configuration and pass the config
        //  value straight to the query. TRUST.
        $result = $this->db->query("SELECT * FROM {$this->emailsTable}");
        $emails = [];

        while ($item = $result->fetch_object()) {
            $emails[] = $item;
        }

        return $emails;
    }

    /**
     * DEEEE.STRUCT.ORRRR *pew pew explosion noises*
     */
    public function __destruct()
    {
        // A relic from the old days, I don't feel you need to do this anymore. BUT I WILL!
        $this->db->close();
    }

}
