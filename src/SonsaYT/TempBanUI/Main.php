<?php

namespace SonsaYT\TempBanUI;

use pocketmine\plugin\PluginBase;
use pocketmine\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

class Main extends PluginBase implements Listener {

	public $playerList = [];
	public $targetPlayer;
	
    public function onEnable() {
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		@mkdir($this->getDataFolder());
		$this->db = new \SQLite3($this->getDataFolder() . "TempBanUI.db");
		$this->db->exec("CREATE TABLE IF NOT EXISTS banPlayers(player TEXT PRIMARY KEY, banTime INT, reason TEXT, staff TEXT);");
		$this->message = (new Config($this->getDataFolder() . "Message.yml", Config::YAML, array(
		"BroadcastBanMessage" => "§b{player} §dhas been banned by §b{staff} §dfor §b{day} §dday/s, §b{hour} §dhour/s, §b{minute} §dminute/s. §dReason: §b{reason}",
		"KickBanMessage" => "§dYou are banned by §b{staff} §dfor §b{day} §dday/s, §b{hour} §dhour/s, §b{minute} §dminute/s. \n§dReason: §b{reason}",
		"LoginBanMessage" => "§dYou are still banned for §b{day} §dday/s, §b{hour} §dhour/s, §b{minute} §dminute/s, §b{second} §dsecond/s. \n§dReason: §b{reason} \n§dBanned by: §b{staff}",
		"BanMyself" => "§cYou can't ban yourself",
		"NoBanPlayers" => "§bNo ban players",
		"UnBanPlayer" => "§b{player} has been unban",
		"AutoUnBanPlayer" => "§b{player} has been auto unban. Ban time already done",
		"BanListTitle" => "§lBAN PLAYER LIST",
		"BanListContent" => "Choose player",
		"InfoUIContent" => "§dInformation: \nDay: §b{day} \n§dHour: §b{hour} \n§dMinute: §b{minute} \n§dSecond: §b{second} \n§dReason: §b{reason} \n§dBanned by: §b{staff}\n\n\n",
		"InfoUIUnBanButton" => "Unban Player",
		)))->getAll();
    }

    public function onCommand(CommandSender $sender, Command $cmd, string $label,array $args) : bool {
		switch($cmd->getName()){
			case "tban":
				if($sender instanceof Player) {
					if($sender->hasPermission("use.tban")){
						$this->openTbanUI($sender);
					}
				}
				else{
					$sender->sendMessage(TextFormat::RED . "Use this Command in-game.");
					return true;
				}
			break;
			case "tcheck":
				if($sender instanceof Player) {
					if($sender->hasPermission("use.tcheck")){
						$this->openTcheckUI($sender);
					}
				}
			break;
		}
		return true;
    }
	
	public function openTbanUI($sender){
		$api = $this->getServer()->getPluginManager()->getPlugin("FormAPI");
		$form = $api->createCustomForm(function (Player $sender, array $data = null){
			$result = $data[0];
			if($result === null){
				return true;
			}
			$c = 0;
			foreach($this->playerList as $player){
				if($result == $c){
					$target = $player->getPlayer();	
					if($target instanceof Player){
						if($target->getName() == $sender->getName()){
							$player->sendMessage($this->message["BanMyself"]);
							return true;
						}
						$now = time();
						$day = ($data[1] * 86400);
						$hour = ($data[2] * 3600);
						$min = ($data[3] * 60);
						$banTime = $now + $day + $hour + $min;
						$banInfo = $this->db->prepare("INSERT OR REPLACE INTO banPlayers (player, banTime, reason, staff) VALUES (:player, :banTime, :reason, :staff);");
						$banInfo->bindValue(":player", $target->getName());
						$banInfo->bindValue(":banTime", $banTime);
						$banInfo->bindValue(":reason", $data[4]);
						$banInfo->bindValue(":staff", $sender->getName());
						$result = $banInfo->execute();
						$target->kick(str_replace(["{day}", "{hour}", "{minute}", "{reason}", "{staff}"], [$data[1], $data[2], $data[3], $data[4], $sender->getName()], $this->message["KickBanMessage"]));
						$this->getServer()->broadcastMessage(str_replace(["{player}", "{day}", "{hour}", "{minute}", "{reason}", "{staff}"], [$target->getName(), $data[1], $data[2], $data[3], $data[4], $sender->getName()], $this->message["BroadcastBanMessage"]));
						foreach($this->playerList as $player){
							unset($this->playerList[strtolower($player->getName())]);
						}
					}
				}
				$c++;
			}
		});
		foreach($this->getServer()->getOnlinePlayers() as $player){
			$player = $player->getPlayer();
			$this->playerList[strtolower($player->getName())] = $player;
			$list[] = $player->getName();
		}
		$form->setTitle(TextFormat::BOLD . "TEMPORARY BAN");
		$form->addDropdown("\nChoose player", $list);
		$form->addSlider("Day/s", 0, 30, 1);
		$form->addSlider("Hour/s", 0, 24, 1);
		$form->addSlider("Minute/s", 1, 60, 5);
		$form->addInput("Reason");
		$form->sendToPlayer($sender);
		return $form;
	}
	
	public function openTcheckUI($sender){
		$api = $this->getServer()->getPluginManager()->getPlugin("FormAPI");
		$form = $api->createSimpleForm(function (Player $sender, int $data = null){
		$result = $data;
		if($result === null){
			return true;
		}
			$banInfo = $this->db->query("SELECT * FROM banPlayers;");
			$i = -1;
			while ($resultArr = $banInfo->fetchArray(SQLITE3_ASSOC)) {
				$j = $i + 1;
				$banPlayer = $resultArr['player'];
				$i = $i + 1;
				if($result == $j){
					$this->targetPlayer = $banPlayer;
					$this->openInfoUI($sender);
				}
			}
		});
		$banInfo = $this->db->query("SELECT * FROM banPlayers;");
		$array = $banInfo->fetchArray(SQLITE3_ASSOC);	
		if (empty($array)) {
			$sender->sendMessage($this->message["NoBanPlayers"]);
			return true;
		}
		$form->setTitle($this->message["BanListTitle"]);
		$form->setContent($this->message["BanListContent"]);
		$banInfo = $this->db->query("SELECT * FROM banPlayers;");
		$i = -1;
		while ($resultArr = $banInfo->fetchArray(SQLITE3_ASSOC)) {
			$j = $i + 1;
			$banPlayer = $resultArr['player'];
			$form->addButton(TextFormat::BOLD . "$banPlayer");
			$i = $i + 1;
		}
		$form->sendToPlayer($sender);
		return $form;
	}
	
	public function openInfoUI($sender){
		$api = $this->getServer()->getPluginManager()->getPlugin("FormAPI");
		$form = $api->createSimpleForm(function (Player $sender, int $data = null){
		$result = $data;
		if($result === null){
			return true;
		}
			switch($result){
				case 0:
					$banplayer = $this->targetPlayer;
					$banInfo = $this->db->query("SELECT * FROM banPlayers WHERE player = '$banplayer';");
					$array = $banInfo->fetchArray(SQLITE3_ASSOC);
					if (!empty($array)) {
						$this->db->query("DELETE FROM banPlayers WHERE player = '$banplayer';");
						$sender->sendMessage(str_replace(["{player}"], [$banplayer], $this->message["UnBanPlayer"]));
					}
				break;
			}
		});
		$banPlayer = $this->targetPlayer;
		$banInfo = $this->db->query("SELECT * FROM banPlayers WHERE player = '$banPlayer';");
		$array = $banInfo->fetchArray(SQLITE3_ASSOC);
		if (!empty($array)) {
			$banTime = $array['banTime'];
			$reason = $array['reason'];
			$staff = $array['staff'];
			$now = time();
			if($banTime < $now){
				$banplayer = $this->targetPlayer;
				$banInfo = $this->db->query("SELECT * FROM banPlayers WHERE player = '$banplayer';");
				$array = $banInfo->fetchArray(SQLITE3_ASSOC);
				if (!empty($array)) {
					$this->db->query("DELETE FROM banPlayers WHERE player = '$banplayer';");
					$sender->sendMessage(str_replace(["{player}"], [$banplayer], $this->message["AutoUnBanPlayer"]));
				}
				return true;
			}
			$remainingTime = $banTime - $now;
			$day = floor($remainingTime / 86400);
			$hourSeconds = $remainingTime % 86400;
			$hour = floor($hourSeconds / 3600);
			$minuteSec = $hourSeconds % 3600;
			$minute = floor($minuteSec / 60);
			$remainingSec = $minuteSec % 60;
			$second = ceil($remainingSec);
		}
		$form->setTitle(TextFormat::BOLD . $banPlayer);
		$form->setContent(str_replace(["{day}", "{hour}", "{minute}", "{second}", "{reason}", "{staff}"], [$day, $hour, $minute, $second, $reason, $staff], $this->message["InfoUIContent"]));
		$form->addButton($this->message["InfoUIUnBanButton"]);
		$form->sendToPlayer($sender);
		return $form;
	}

	public function onPlayerLogin(PlayerPreLoginEvent $event){
		$player = $event->getPlayer();
		$banInfo = $this->db->query("SELECT * FROM banPlayers;");
		$array = $banInfo->fetchArray(SQLITE3_ASSOC);	
		if (!empty($array)) {
			$banInfo = $this->db->query("SELECT * FROM banPlayers;");
			$i = -1;
			while ($resultArr = $banInfo->fetchArray(SQLITE3_ASSOC)) {
				$j = $i + 1;
				$banplayer = $resultArr['player'];
				if($player->getName() == $banplayer){
					$banInfo = $this->db->query("SELECT * FROM banPlayers WHERE player = '$banplayer';");
					$array = $banInfo->fetchArray(SQLITE3_ASSOC);
					if (!empty($array)) {
						$banTime = $array['banTime'];
						$reason = $array['reason'];
						$staff = $array['staff'];
						$now = time();
						if($banTime > $now){
							$remainingTime = $banTime - $now;
							$day = floor($remainingTime / 86400);
							$hourSeconds = $remainingTime % 86400;
							$hour = floor($hourSeconds / 3600);
							$minuteSec = $hourSeconds % 3600;
							$minute = floor($minuteSec / 60);
							$remainingSec = $minuteSec % 60;
							$second = ceil($remainingSec);
							$player->close("", str_replace(["{day}", "{hour}", "{minute}", "{second}", "{reason}", "{staff}"], [$day, $hour, $minute, $second, $reason, $staff], $this->message["LoginBanMessage"]));
						} else {
							$banInfo = $this->db->query("SELECT * FROM banPlayers WHERE player = '$banplayer';");
							$array = $banInfo->fetchArray(SQLITE3_ASSOC);
							if (!empty($array)) {
								$this->db->query("DELETE FROM banPlayers WHERE player = '$banplayer';");
							}
						}
					}
				}
				$i = $i + 1;
			}
		}
	}

}
