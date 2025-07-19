<?php

declare(strict_types=1);

namespace AuthME;

use pocketmine\plugin\PluginBase;
use pocketmine\player\Player;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\server\CommandEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\utils\Config;
use pocketmine\world\Position;
use pocketmine\scheduler\ClosureTask;

class Main extends PluginBase implements Listener {
    
    private array $authenticatedPlayers = [];
    private array $playerPins = [];
    private array $playerIPs = [];
    private array $loginAttempts = [];
    private array $frozenPlayers = [];
    private Config $config;
    private ?Position $loginLocation = null;
    
    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->config = $this->getConfig();
        
        // Create data folder if it doesn't exist
        if (!is_dir($this->getDataFolder())) {
            mkdir($this->getDataFolder(), 0755, true);
        }
        
        // Load player data
        $this->loadPlayerData();
        
        // Load login location if set
        $this->loadLoginLocation();
        
        $this->getLogger()->info("AuthME Plugin enabled successfully!");
    }
    
    public function onDisable(): void {
        $this->savePlayerData();
        $this->saveLoginLocation();
    }
    
    private function loadPlayerData(): void {
        $dataFile = $this->getDataFolder() . "players.json";
        if (file_exists($dataFile)) {
            $data = json_decode(file_get_contents($dataFile), true);
            $this->playerPins = $data['pins'] ?? [];
            $this->playerIPs = $data['ips'] ?? [];
        }
    }
    
    private function savePlayerData(): void {
        $dataFile = $this->getDataFolder() . "players.json";
        $data = [
            'pins' => $this->playerPins,
            'ips' => $this->playerIPs
        ];
        file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    private function loadLoginLocation(): void {
        $locFile = $this->getDataFolder() . "loginloc.json";
        if (file_exists($locFile)) {
            $data = json_decode(file_get_contents($locFile), true);
            if ($data && isset($data['world'], $data['x'], $data['y'], $data['z'])) {
                $world = $this->getServer()->getWorldManager()->getWorldByName($data['world']);
                if ($world !== null) {
                    $this->loginLocation = new Position($data['x'], $data['y'], $data['z'], $world);
                }
            }
        }
    }
    
    private function saveLoginLocation(): void {
        if ($this->loginLocation !== null) {
            $locFile = $this->getDataFolder() . "loginloc.json";
            $data = [
                'world' => $this->loginLocation->getWorld()->getFolderName(),
                'x' => $this->loginLocation->getX(),
                'y' => $this->loginLocation->getY(),
                'z' => $this->loginLocation->getZ()
            ];
            file_put_contents($locFile, json_encode($data, JSON_PRETTY_PRINT));
        }
    }
    
    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $name = $player->getName();
        $ip = $player->getNetworkSession()->getIp();
        
        // Check if player is registered
        if (!isset($this->playerPins[$name])) {
            $player->sendMessage("§eWelcome! Please set your PIN using: /setpin [PIN]");
            return;
        }
        
        // Check IP authentication (daily)
        if (isset($this->playerIPs[$name]) && $this->playerIPs[$name]['ip'] === $ip) {
            $lastLogin = $this->playerIPs[$name]['time'] ?? 0;
            if (time() - $lastLogin < 86400) { // 24 hours
                $this->authenticatedPlayers[$name] = true;
                $player->sendMessage("§aAuthenticated via saved IP!");
                return;
            }
        }
        
        // Freeze player and require PIN
        $this->frozenPlayers[$name] = true;
        $player->sendMessage("§cPlease enter your PIN: /login [PIN]");
        
        // Teleport to login location if set
        if ($this->loginLocation !== null) {
            $player->teleport($this->loginLocation);
        }
        
        // Auto-kick after timeout
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($name): void {
            $player = $this->getServer()->getPlayerByPrefix($name);
            if ($player !== null && !$this->isAuthenticated($name)) {
                $player->kick("§cAuthentication timeout!");
            }
        }), 20 * 60); // 60 seconds
    }
    
    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $name = $event->getPlayer()->getName();
        unset($this->authenticatedPlayers[$name]);
        unset($this->frozenPlayers[$name]);
        unset($this->loginAttempts[$name]);
    }
    
    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        if (isset($this->frozenPlayers[$player->getName()])) {
            $event->cancel();
        }
    }
    
    public function onPlayerChat(PlayerChatEvent $event): void {
        $player = $event->getPlayer();
        if (isset($this->frozenPlayers[$player->getName()])) {
            $event->cancel();
            $player->sendMessage("§cPlease authenticate first with /login [PIN]");
        }
    }
    
    public function onCommandExecute(CommandEvent $event): void {
        $sender = $event->getSender();
        $command = $event->getCommand();
        
        if (!$sender instanceof Player) {
            return;
        }
        
        $commandName = explode(" ", $command)[0];
        $allowedCommands = ["login", "setpin", "resetpin"];
        
        if (isset($this->frozenPlayers[$sender->getName()]) && !in_array(strtolower($commandName), $allowedCommands)) {
            $event->cancel();
            $sender->sendMessage("§cPlease authenticate first with /login [PIN]");
        }
    }
    
    public function onInventoryTransaction(InventoryTransactionEvent $event): void {
        $player = $event->getTransaction()->getSource();
        if (isset($this->frozenPlayers[$player->getName()])) {
            $event->cancel();
        }
    }
    
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage("§cThis command can only be used in-game!");
            return false;
        }
        
        $name = $sender->getName();
        
        switch (strtolower($command->getName())) {
            case "setpin":
                if (count($args) !== 1) {
                    $sender->sendMessage("§cUsage: /setpin [PIN]");
                    return false;
                }
                
                $pin = $args[0];
                $minLength = $this->config->get("min-pin-length", 4);
                $maxLength = $this->config->get("max-pin-length", 16);
                
                if (strlen($pin) < $minLength || strlen($pin) > $maxLength) {
                    $sender->sendMessage("§cPIN must be between {$minLength} and {$maxLength} characters!");
                    return false;
                }
                
                $this->playerPins[$name] = password_hash($pin, PASSWORD_DEFAULT);
                $sender->sendMessage("§aPIN set successfully!");
                $this->savePlayerData();
                return true;
                
            case "login":
                if (count($args) !== 1) {
                    $sender->sendMessage("§cUsage: /login [PIN]");
                    return false;
                }
                
                if (!isset($this->playerPins[$name])) {
                    $sender->sendMessage("§cPlease set a PIN first with /setpin [PIN]");
                    return false;
                }
                
                $pin = $args[0];
                
                // Check rate limiting
                if (!isset($this->loginAttempts[$name])) {
                    $this->loginAttempts[$name] = 0;
                }
                
                if ($this->loginAttempts[$name] >= 3) {
                    $sender->kick("§cToo many failed login attempts!");
                    return false;
                }
                
                if (password_verify($pin, $this->playerPins[$name])) {
                    $this->authenticatedPlayers[$name] = true;
                    unset($this->frozenPlayers[$name]);
                    unset($this->loginAttempts[$name]);
                    
                    // Save IP for daily authentication
                    $this->playerIPs[$name] = [
                        'ip' => $sender->getNetworkSession()->getIp(),
                        'time' => time()
                    ];
                    
                    $sender->sendMessage("§aAuthentication successful! Welcome back!");
                    $this->savePlayerData();
                } else {
                    $this->loginAttempts[$name]++;
                    $remaining = 3 - $this->loginAttempts[$name];
                    $sender->sendMessage("§cIncorrect PIN! Attempts remaining: {$remaining}");
                }
                return true;
                
            case "resetpin":
                if (!$sender->hasPermission("al.admin.resetpin")) {
                    $sender->sendMessage("§cYou don't have permission to use this command!");
                    return false;
                }
                
                if (count($args) !== 1) {
                    $sender->sendMessage("§cUsage: /resetpin [Player]");
                    return false;
                }
                
                $targetName = $args[0];
                $target = $this->getServer()->getPlayerByPrefix($targetName);
                
                if (isset($this->playerPins[$targetName])) {
                    unset($this->playerPins[$targetName]);
                    unset($this->playerIPs[$targetName]);
                    $sender->sendMessage("§aReset PIN for player: {$targetName}");
                    
                    if ($target !== null) {
                        $target->kick("§cYour PIN has been reset by an administrator!");
                    }
                    
                    $this->savePlayerData();
                } else {
                    $sender->sendMessage("§cPlayer {$targetName} doesn't have a PIN set!");
                }
                return true;
                
            case "alreload":
                if (!$sender->hasPermission("al.admin.reload")) {
                    $sender->sendMessage("§cYou don't have permission to use this command!");
                    return false;
                }
                
                $this->reloadConfig();
                $this->config = $this->getConfig();
                $sender->sendMessage("§aAuthME configuration reloaded!");
                return true;
                
            case "setjoinloc":
                if (!$sender->hasPermission("al.admin.setloc")) {
                    $sender->sendMessage("§cYou don't have permission to use this command!");
                    return false;
                }
                
                $this->loginLocation = $sender->getPosition();
                $this->saveLoginLocation();
                $sender->sendMessage("§aLogin location set to your current position!");
                return true;
        }
        
        return false;
    }
    
    private function isAuthenticated(string $playerName): bool {
        return isset($this->authenticatedPlayers[$playerName]);
    }
}