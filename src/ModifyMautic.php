<?php

class ModifyMautic
{
    protected $dir;
    protected $db;
    protected $config;
    protected $emailsTable;
    protected $emailsStructure = [];
    protected $textFields      = [];
    protected $varcharFields   = [];

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
                if ($row['Type'] == 'text') {
                    $this->textFields[] = $row['Field'];
                } else {
                    $this->varcharFields[] = $row['Field'];
                }
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
        throw new \Exception($errorMessage);
    }

    /**
     * Replace {{item}} with $email->item if it exists, within content.
     *
     * @param $email
     * @param $content
     *
     * @return mixed
     */
    public function replaceContent($email, $content)
    {
        if (!empty($this->emailsStructure) && is_array($this->emailsStructure)) {
            foreach ($email as $key => $item) {
                $content = str_replace("{{{$key}}}", $email->{$key}, $content);
            }
        }

        return $content;
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
        $statement->bind_param('s', $bind);
        $statement->bind_param('i', $id);
        $statement->execute();
        $result = $statement->get_result();
        if ($result->num_rows) {
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

            $email = $result->fetch_object();
            if (!empty($email->content)) {
                $content = unserialize($email->content);
                foreach ($content as $key => $value) {
                    if (!in_array($key, $this->emailsStructure)) {
                        $this->emailsStructure[] = $key;
                    }
                    $email->{$key} = $value;
                }
            }

            return $email;
        }

        return false;
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


