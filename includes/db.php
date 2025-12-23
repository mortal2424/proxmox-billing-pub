<?php
require_once 'config.php';

class Database {
    private $pdo;
    
    public function __construct() {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection error");
        }
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    /**
     * Получает активный тикет для ноды
     */
    public function getProxmoxTicket($nodeId) {
        $stmt = $this->pdo->prepare("
            SELECT ticket, csrf_token 
            FROM proxmox_tickets 
            WHERE node_id = ? AND expires_at > NOW()
            ORDER BY expires_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$nodeId]);
        return $stmt->fetch();
    }
    
    /**
     * Сохраняет или обновляет тикет Proxmox
     */
    public function saveProxmoxTicket($nodeId, $ticket, $csrfToken, $expiresAt) {
        $stmt = $this->pdo->prepare("
            INSERT INTO proxmox_tickets 
            (node_id, ticket, csrf_token, expires_at)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                ticket = VALUES(ticket),
                csrf_token = VALUES(csrf_token),
                expires_at = VALUES(expires_at)
        ");
        return $stmt->execute([$nodeId, $ticket, $csrfToken, $expiresAt]);
    }
    
    /**
     * Удаляет устаревшие тикеты
     */
    public function cleanupProxmoxTickets() {
        $stmt = $this->pdo->prepare("
            DELETE FROM proxmox_tickets 
            WHERE expires_at < NOW()
        ");
        return $stmt->execute();
    }
    
    /**
     * Получает данные ноды по ID
     */
    public function getProxmoxNode($nodeId) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM proxmox_nodes 
            WHERE id = ?
        ");
        $stmt->execute([$nodeId]);
        return $stmt->fetch();
    }
    
    /**
     * Транзакционная обертка для запросов
     */
    public function transaction(callable $callback) {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}