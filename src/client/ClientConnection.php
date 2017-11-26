<?php

namespace client;

use pocketmine\network\mcpe\protocol\DataPacket as PMDataPacket;
use raklib\protocol\ACK;
use raklib\protocol\DATA_PACKET_0;
use raklib\protocol\DATA_PACKET_4;
use raklib\protocol\DataPacket;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\Packet;
use raklib\protocol\PONG_DataPacket;
use raklib\protocol\SERVER_HANDSHAKE_DataPacket;
use raklib\protocol\UNCONNECTED_PING;
use raklib\server\UDPServerSocket;
use client\Tickable;

class ClientConnection extends UDPServerSocket implements Tickable{

	const START_PORT = 49666;

	private static $instanceId = 0;

	private $isConnected;

	/** @var  MCPEClient */
	private $client;
	private $ip;
	private $port;

	private $name;
	private $clientId;

	private $sequenceNumber;
	private $ackQueue;

	private $lastSendTime;
	private $pingCount;

	public function __construct(MCPEClient $client, $ip, $port){
		$this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		if(@socket_bind($this->socket, "0.0.0.0", ClientConnection::START_PORT + ClientConnection::$instanceId) === true){
			socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 0);
			$this->setSendBuffer(1024 * 1024 * 8)->setRecvBuffer(1024 * 1024 * 8);
		}
		socket_set_nonblock($this->socket);
		ClientConnection::$instanceId++;

		$this->client = $client;
		$this->ip = $ip;
		$this->port = $port;
		$this->name = "";
		$this->clientId = mt_rand(1000, 9999);

		$this->sequenceNumber = 0;
		$this->ackQueue = [];
		$this->isConnected = false;
		$this->lastSendTime = -1;
		$this->pingCount = 0;
	}

	/**
	   * @return string
	   */
	public function getName(){
		return $this->name;
	}

	/**
	   * @param string $name
	   */
	public function setName($name){
		$this->name = $name;
	}

	/**
	   * @param int
	   */
	public function getClientId(){
		return $this->clientId;
	}

	/**
	   * @param int $id
	   */
	public function setClientId($clientId){
		$this->clientId = $clientId;
	}

	public function sendPacket(Packet $packet){
		echo "[Send] " . get_class($packet) . PHP_EOL;
		$this->lastSendTime = time();
		$packet->encode();
		return $this->writePacket($packet->buffer, $this->ip, $this->port);
	}

	public function sendEncapsulatedPacket($packet){
		if($packet instanceof Packet || $packet instanceof PMDataPacket) {
			echo "[Send] " . get_class($packet) . PHP_EOL;
			$packet->encode();
			$encapsulated = new EncapsulatedPacket();
			$encapsulated->reliability = 0;
			$encapsulated->buffer = $packet->buffer;

			$sendPacket = new DATA_PACKET_4();
			$sendPacket->seqNumber = $this->sequenceNumber++;
			$sendPacket->packets[] = $encapsulated->toBinary();

			return $this->sendPacket($sendPacket);
		}else{
			return false;
		}
	}

	public function receivePacket(){
		if ($this->readPacket($buffer, $this->ip, $this->port) > 0) {
			if (($packet = StaticPacketPool::getPacketFromPool(ord($buffer{0}))) !== null) {
				$packet->buffer = $buffer;
				$packet->decode();
				if ($packet instanceof DataPacket) {
					$this->ackQueue[$packet->seqNumber] = $packet->seqNumber;
				}
				return $packet;
			}
			return $buffer;
		}else{
			return false;
		}
	}

	public function tick(){
		if(!$this->isConnected() && $this->lastSendTime !== time()){
			$ping = new UNCONNECTED_PING();
			$ping->pingID = $this->pingCount++;
			$this->sendPacket($ping);
		}
		if(count($this->ackQueue) > 0 && $this->lastSendTime !== time()){
			$ack = new ACK();
			$ack->packets = $this->ackQueue;
			$this->sendPacket($ack);
			$this->ackQueue = [];
		}
		$pk = $this->receivePacket();
		if($pk instanceof Packet){
			if($pk instanceof DataPacket){
				foreach($pk->packets as $pk){
					$id = ord($pk->buffer{0});
					if(SERVER_HANDSHAKE_DataPacket::$ID === $id){
						$new = new SERVER_HANDSHAKE_DataPacket();
						$new->buffer = $pk->buffer;
						$new->decode();
						$this->client->handlePacket($this, $new);
					}elseif(PONG_DataPacket::$ID === $id){
						$new = new PONG_DataPacket();
						$new->buffer = $pk->buffer;
						$new->decode();
						$this->client->handlePacket($this, $new);
					}else{
						$new = StaticDataPacketPool::getPacket($pk->buffer);
						$new->decode();
						$this->client->handleDataPacket($this, $new);
					}
				}
			}else{
				$this->client->handlePacket($this, $pk);
			}
		}elseif($pk !== false){
			echo $pk . PHP_EOL;
		}
	}

	/**
	   * @return MCPEClient
	   */
	public function getClient(){
		return $this->client;
	}

	/**
	   * @return int
	   */
	public function getIp(){
		return $this->ip;
	}

	/**
	   * @return string
	   */
	public function getPort(){
		return $this->port;
	}

	/**
	   * @return boolean
	   */
	public function isConnected(){
		return $this->isConnected;
	}

	/**
	   * @param boolean $isConnected
	   */
	public function setIsConnected($isConnected){
		$this->isConnected = $isConnected;
	}
}