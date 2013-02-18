<?php
/**
 * Minecraft Query Class - PHP5 method of querying Minecraft servers
 * Copyright (C) 2013 Shannon Wynter
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * Changelog
 * ------------
 * version 1.0, 2013-02-18, Shannon Wynter {@link http://fremnet.net/contact}
 * - initial release
 *
 * @version 1.0
 * @author Shannon Wynter {@link http://fremnet.net/contact}
 * @copyright Copyright &copy; 2013 Shannon Wynter (Fremnet)
 * @license http://www.gnu.org/licenses/gpl-2.0.html GPL 2.0 or greater
 * @package Fremnet
 * @subpackage Minecraft
 */

/**
 * Minecraft Query Class
 *
 * <b>Synopsis:</b>
 *
 * <i>General Usage:</i>
 * <code>
 * include('mcquery.class.php');
 * $q = new MCQuery('121.45.193.22');
 * $q->connect();
 * print_r($q->basic_status());
 * </code>
 *
 * @version 1.0.0
 * @author Shannon Wynter (http://fremnet.net/contact)
 * @copyright Copyright (c) 2013, Shannon Wynter (Fremnet)
 * @license http://www.gnu.org/licenses/gpl-2.0.html GPL 2.0 or greater
 * @package Fremnet
 * @subpackage Minecraft
 */
class MCQuery {
	/**#@+
	 * Constants required for selecting the packet type
	 */
	/**
	 * Status packet
	 */
	const PACKET_TYPE_STATUS = 0;
	/**
	 * Challenge packet
	 */
	const PACKET_TYPE_CHALLENGE = 9;
	/**#@-*/

	/**
	 * Internal handshake retry counter
	 */
	private $retries = 0;
	/**
	 * Maximum attempts at retrying
	 */
	private $max_retries = 3;

	/**
	 * How long to wait for UDP packets to become available
	 */
	private $read_timeout = 2;

	/**
	 * Current packet ID, not really used by this class, more to be polite to the server
	 */
	private $id = 0;
	/**
	 * Storage for the classic protocol challange
	 */
	private $challenge;

	private $host;
	private $port = 25565;

	private $socket;

	/**
	 * Construct
	 *
	 * @param string $host the host to connect to
	 * @param integer $port set to null to take the default of 25565
	 * @param integer $max_retries set to null to take the default of 3 retries
	 * @param integer $read_timeout set to null to take the default read timeout of 2 seconds
	 */
	public function __construct($host, $port = null, $max_retries = null, $read_timeout = null) {
		if (!is_null($max_retries))
			$this->max_retries = $max_retries;
		if (!is_null($read_timeout))
			$this->read_timeout = $timeout;
		if (!is_null($port))
			$this->port = $port;

		$this->host = $host;
	}

	/**
	 * Connect
	 *
	 * 'Connect' the udp socket and initiate handshake
	 *
	 * @throws Exception
	 */
	public function connect() {
		$this->socket = fsockopen('udp://' . $this->host, $this->port, $errno, $errstr, 30);

		if (!$this->socket)
			throw new Exception("Unable to open socket: $errstr ($errno)");

		$this->id = 0;
		$this->challenge = '';

		$this->handshake();
	}

	/**
	 * Disconnect
	 *
	 * Disconnect the udp socket (is it ever really connected?)
	 *
	 * @throws MCNotConnectedException
	 */
	public function disconnect() {
		if (!$this->socket)
			throw new MCNotConnectedException("Socket not opened");
		fclose($this->socket);
		unset($this->socket);
	}

	/**
	 * Handshake
	 *
	 * Perform the MCQuery protocol handshake described on dinnerbone's website
	 *
	 * @see http://dinnerbone.com/blog/2011/10/14/minecraft-19-has-rcon-and-query/
	 * @throws MCNotConnectedException
	 */
	public function handshake() {
		if (!$this->socket)
			throw new MCNotConnectedException("Socket not opened");

		$this->id += 1;

		$this->write_packet(self::PACKET_TYPE_CHALLENGE, '');
		try {
			list($type, $id, $buffer) = $this->read_packet();
		}
		catch (Exception $e) {
			$this->retries += 1;
			if ($this->retries = $this->max_retries)
				throw new Exception("Retry limit reached - Server down?");
			return $this->handshake();
		}

		$this->retries = 0;
		$this->challenge = pack('N', (int) $buffer);
	}

	/**
	 * Basic Status
	 *
	 * Retrieve basic server status
	 *
	 * Example output
	 * Array (
	 * 	[motd]       => A Minecraft Server
	 * 	[gametype]   => SMP
	 * 	[map]        => world
	 * 	[numplayers] => 2
	 * 	[maxplayers] => 20
	 * 	[hostname]   => 127.0.0.1
	 * 	[port]       => 25565
	 * )
	 *
	 * @throws MCNotConnectedException
	 * @throws MCNoChallengeException
	 * @returns array associative array of name => value
	 */
	public function basic_status() {
		if (!$this->socket)
			throw new MCNotConnectedException("Socket not opened");
		if (!$this->challenge)
			throw new MCNoChallengeException("No challenge exists");

		$this->write_packet(self::PACKET_TYPE_STATUS, $this->challenge);
		try {
			list($type, $id, $buffer) = $this->read_packet();
		}
		catch (Exception $e) {
			$this->handshake();
			return $this->basic_status();
		}

		$list = explode("\x00", $buffer, 6);
		$buffer = array_pop($list);

		$result = array_combine(
			array('motd', 'gametype', 'map', 'numplayers', 'maxplayers'),
			$list
		);

		list($result['port'], $result['hostname']) = array_values(unpack('vp/a' . (strlen($buffer) - 2) . 'h', $buffer));

		return $result;
	}

	/**
	 * Full status
	 *
	 * Retrieve the full server status including player list
	 *
	 * Example output
	 * Array (
	 * 	[motd] => A Minecraft Server
	 * 	[gametype] => SMP
	 * 	[game_id] => MINECRAFT
	 * 	[version] => 1.4.7
	 * 	[plugins] =>
	 * 	[map] => world
	 * 	[numplayers] => 2
	 * 	[maxplayers] => 20
	 * 	[hostport] => 25565
	 * 	[hostip] => 127.0.0.1
	 * 	[players] => Array (
	 * 		[0] => fredblogs
	 * 		[1] => maryblogs
	 * 	)
	 * )
	 *
	 * @throws MCNotConnectedException
	 * @throws MCNoChallengeException
	 * @return array associative array of server data
	 */
	public function full_status() {
		if (!$this->socket)
			throw new MCNotConnectedException("Socket not opened");
		if (!$this->challenge)
			throw new MCNoChallengeException("No challenge exists");

		$this->write_packet(self::PACKET_TYPE_STATUS, $this->challenge . "\x00\x00\x00\x00");
		try {
			list ($type, $id, $buffer) = $this->read_packet();
		}
		catch (Exception $e) {
			$this->handshake();
			return $this->full_status();
		}

		$buffer = substr($buffer, 11);
		list($items, $players) = explode("\x00\x00\x01player_\x00\x00", $buffer);

		// Notch has the wrong field name assigned to the motd, replace it
		$items = $this->array_mutate(explode("\x00", "motd" . substr($items, 8)));
		$items['players'] = explode("\x00", rtrim($players, "\x00"));

		return $items;
	}

	/**
	 * Array Mutate
	 *
	 * Quickly convert any given array of name, value into an
	 * associative array of name => value.
	 *
	 * NB: Not really sutable for large arrays.
	 *
	 * @throws LengthException
	 * @param array $array even [name, value] array
	 * @return array associative array of [name => value]
	 */
	protected function array_mutate(array $array) {
		if (count($array) % 2)
			throw new LengthException("Expecting even length array");

		if (count($array) == 0)
			return array();

		return array_combine(
				array_intersect_key($array, array_flip(range(0, count($array), 2))),
				array_intersect_key($array, array_flip(range(1, count($array), 2)))
		);
	}

	/**
	 * Write packet
	 *
	 * Send a MCQuery packet to the server
	 *
	 * $type would be PACKET_TYPE_STATUS or PACKET_TYPE_CHALLENGE
	 *
	 * @param integer $type type of packet to send
	 * @param mixed $payload usually a string to send as a payload
	 */
	protected function write_packet($type, $payload) {
		$packet = "\xFE\xFD" . pack('CN', $type, $this->id) . $payload;
		fwrite($this->socket, $packet);
	}

	/**
	 * Read Packet
	 *
	 * Really when hooked up to smart udp read it's going to read as much as
	 * it can, not just one packet.
	 *
	 * Read and unpack the basic information from a MCQuery packet
	 *
	 * @return array Type of packet, Id of packet, Buffer
	 */
	protected function read_packet() {
		$buffer = $this->smart_udp_read();
		list($type, $id) = array_values(unpack('Cc/Nn', $buffer));
		return array($type, $id, substr($buffer, 5));
	}

	/**
	 * Smart UDP Read
	 *
	 * Face it, when it comes to reading UDP packets, PHP is rather dumb
	 * This gives us a slightly smarter udp read that waits for the socket to be unblocked
	 * and keeps reading until there's no more waiting data.
	 *
	 * @throws MCNotConnectedException
	 * @return string
	 */
	protected function smart_udp_read() {
		if (!$this->socket)
			throw new MCNotConnectedException("Socket not opened");

		$string_length = $timer = 0;
		$data  = '';

		// Wait for the socket to be ready and the data to appear - until read_timeout
		while (strlen($data) == 0) {
			if ($timer < $this->read_timeout) {
				$data .= fgets($this->socket, 2);
				usleep(1);
				$timer++;
			} else
				return 0;
		}
		// Keep reading until the length recorded matches the actual length - in hopes
		// that unread_bytes will keep up :)
		while ($string_length < strlen($data)) {
			$socket_status = socket_get_status($this->socket);
			$string_length = strlen($data);
			$data .= fgets($this->socket, $socket_status['unread_bytes'] + 1);
		}
		return $data;
	}
}

class MCException extends Exception {};
class MCNotConnectedException extends MCException {};
class MCNoChallengeException extends MCException {};
