<?php

class Log_model extends Model
{
    /**
     * Summary of table
     * 
     * @var string
     */
    public $table = 'logs';

    /**
     * Get all settings
     * 
     * @return array
     */
    public function getAll()
    {
        $database = Database::openConnection();
        $database->getAll($this->table);

        $data = $database->fetchAll();

        return $data;
    }

    /**
     * Get logs by user ID
     * 
     * @param int $userId The user ID
     * @return array
     */
    public function getByUserId($userId)
    {
        $database = Database::openConnection();
        $database->prepare("SELECT * FROM $this->table WHERE `user_id` = :user_id ORDER BY `time` ASC");
        $database->bindValue(':user_id', $userId);
        
        if (!$database->execute()) {
            throw new Exception('Something unexpected went wrong');
        }

        $data = $database->fetchAll();

        return $data;
    }

    /**
     * Add log
     * 
     * @param int $userId The user id
     * @param string $payload The payload url
     * @throws Exception
     * @return bool
     */
    public function add($userId, $description, $ip)
    {
        $database = Database::openConnection();

        $database->prepare("INSERT INTO $this->table (`user_id`, `description`, `ip`, `time`) VALUES (:user_id, :description, :ip, :time);");
        $database->bindValue(':user_id', $userId);
        $database->bindValue(':description', $description);
        $database->bindValue(':ip', $ip);
        $database->bindValue(':time', time());

        if (!$database->execute()) {
            throw new Exception('Something unexpected went wrong');
        }

        return true;
    }

}