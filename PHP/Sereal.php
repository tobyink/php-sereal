<?php

# Global namespace
namespace {

	function sereal_encode ($o, $header=true)
	{
		global $__sereal_encode;
		if (empty($__sereal_encode))
			$__sereal_encode = new Sereal\Encoder();
		return $__sereal_encode->encode($o, $header);
	}

	function sereal_decode ($str)
	{
		global $__sereal_decode;
		if (empty($__sereal_decode))
			$__sereal_decode = new Sereal\Decoder();
		return $__sereal_decode->decode($str);
	}

}

namespace Sereal {

	class Unimplemented   extends \Exception      { };
	class ReservedWord    extends Unimplemented   { };
	class Malformed       extends \Exception      { };
	class UnexpectedEnd   extends Malformed       { };
	class UntrackedOffset extends Malformed       { };
	class ForwardOffset   extends UntrackedOffset { };

	define('Sereal\TAG_UNDEF',  "\x25");
	define('Sereal\TAG_FALSE',  "\x3A");
	define('Sereal\TAG_TRUE',   "\x3B");

	function deep_copy ($o)
	{
		return unserialize(serialize($o)); 
	}

	function varint ($i)
	{
		$rv = "";
		do {
			$v = $i & 0x7F;
			$i = $i >> 7;
			if ($i != 0)
				$v |= 0x80;
			$rv .= chr($v);
		} while ($i != 0);
		return $rv;
	}

	function zigzag ($n)
	{
		return ($n < 0) ? varint(-$n*2 - 1) : varint($n*2);
	}

	# probably pretty slow
	function varint_get (&$str, $wantarray=false)
	{
		$encoded = "";
		while ( ord($str) & 0x80 ) {
			$encoded .= substr($str, 0, 1);
			$str = substr($str, 1);
		}
		
		if (empty($str))
			throw new UnexpectedEnd("string unexpectedly ended parsing VARINT");
		
		# final digit
		$encoded .= substr($str, 0, 1);
		$str = substr($str, 1);
		
		$length = strlen($encoded);
		$number = '';
		while (strlen($encoded)) {
			$number  = sprintf('%07b', ord($encoded) & 0x7F) . $number;
			$encoded = substr($encoded, 1);
		}
		
		if ($wantarray)
			return array(bindec($number), $length);
		
		return bindec($number);
	}


	function zigzag_get (&$str, $wantarray=false)
	{
		list($n, $length) = varint_get($str, true);
		$n = ($n % 2) ? -ceil($n/2) : floor($n/2);
		if ($wantarray)
			return array($n, $length);
		return $n;
	}

	function build_regexp ($pattern, $modifiers)
	{
		$pattern = str_replace("/", "\\/", $pattern);
		return "/$pattern/$modifiers";
	}

	function build_weaken (&$ref)
	{
		return $ref;
	}

	# It's not entirely clear how/if this should differ from
	# BINARY in PHP. PHP 5 has no mechanism for flagging a
	# string as UTF8; and PHP 6 is not forthcoming. People
	# may want to use a callback to promote it into a
	# particular class or something though.
	#
	function build_str_utf8 ($bytes)
	{
		return $bytes;
	}

	function build_object ($class, $data)
	{
		if (is_array($data))
			$data['__CLASS__'] = $class;
		return (object)$data;
	}

	class Encoder
	{
		public function encode ($obj, $include_header=true)
		{
			$sereal = '';
			if ($include_header)
				$sereal = $this->_make_header();
				
			$sereal .= $this->_encode($obj);
			
			return $sereal;
		}
		
		protected function _make_header ()
		{
			return "=srl\x01\x00";
		}
		
		protected function _encode ($x)
		{
			if (is_int($x) || is_long($x)) {
				return $this->_encode_integer($x);
			}
			elseif (is_bool($x)) {
				return $x ? TAG_TRUE : TAG_FALSE;
			}
			elseif (is_null($x)) {
				return TAG_UNDEF;
			}
			elseif (is_string($x)) {
				return $this->_encode_string($x);
			}
			elseif (is_float($x)) {
				return $this->_encode_float($x);
			}
			elseif (is_array($x) && count($x) && (array_keys($x) !== range(0, sizeof($x) - 1))) {
				return $this->_encode_hash($x);
			}
			elseif (is_array($x)) {
				return $this->_encode_array($x);
			}
			elseif (is_object($x)) {
				return $this->_encode_object($x);
			}
			else {
				throw new Unimplemented("Don't know what to do with $x");
			}
		}
		
		protected function _encode_integer ($i)
		{
			if ($i >= 0 && $i < 16) {
				return chr($i);
			}
			elseif ($i < 0 && $i > -16) {
				return chr(32 + $i);
			}
			
			if ($i < 0) {
				return "!".zigzag($i);
			}
			else {
				return " ".varint($i);
			}
		}
		
		protected function _encode_string ($bytes)
		{
			$sereal = "+" . varint(strlen($bytes));
			if (strlen($bytes) < 32)
				$sereal = chr(96 + strlen($bytes));
			
			$sereal .= $bytes;
			
			return $sereal;
		}
		
		protected function _encode_array ($arr)
		{
			$sereal = "+" . varint(count($arr));
			if (count($arr) < 16)
				$sereal = chr(64 + count($arr));
			
			for ($i = 0; $i < count($arr); $i++)
				$sereal .= $this->_encode($arr[$i]);
			
			return $sereal;
		}
		
		protected function _encode_object ($o)
		{
			$class = get_class($o);
			$arr   = get_object_vars($o);
			
			if ($class == 'stdClass') {
				if (array_key_exists('__CLASS__', $arr))
					$class = $arr['__CLASS__'];
				else
					$class = null;
			}
			
			$data = $this->_encode($arr);
			if ($class===null)
				return $data;
			
			return sprintf(",%s%s", $this->_encode($class), $data);
		}

		protected function _encode_hash ($arr)
		{
			$sereal = "*" . varint(count($arr));
			if (count($arr) < 16)
				$sereal = chr(80 + count($arr));
			
			foreach ($arr as $k => $v) {
				$sereal .= $this->_encode($k);
				$sereal .= $this->_encode($v);
			}
			
			return $sereal;
		}
		
		protected function _encode_float ($n)
		{
			return '"'.pack('f', $n);
		}
	}

	class Decoder
	{
		protected $bytes;       public function remaining  () { return $this->bytes; }
		protected $offset;
		protected $offset_map;  public function offset_map () { return $this->offset_map; }
		protected $header;      public function header     () { return $this->header; }
		
		public $build_regexp   = 'Sereal\build_regexp';
		public $build_weaken   = 'Sereal\build_weaken';
		public $build_str_utf8 = 'Sereal\build_str_utf8';
		public $build_object   = 'Sereal\build_object';
		
		static protected $_constants = array(
			'25' => null,     # TAG:UNDEF
			'3A' => false,    # TAG:FALSE
			'3B' => true,     # TAG:TRUE
		);
		
		static protected $_track_replace = array(
			'8'  => '0',
			'9'  => '1',
			'A'  => '2',
			'B'  => '3',
			'C'  => '4',
			'D'  => '5',
			'E'  => '6',
			'F'  => '7',
		);
		
		public function decode ($bytes)
		{
			$this->offset     = 0;
			$this->bytes      = $bytes;
			$this->offset_map = array();
			$this->header     = "";
			
			$this->_decode_header();
			return $this->_decode();
		}

		public function decode_remaining ()
		{
			return $this->_decode();
		}

		protected function _decode_header ()
		{
			if ($this->_take(4) != "=srl") {
				throw new Malformed("no magic number");
			}
			
			if ($this->_take(1) != "\x01") {
				throw new Malformed("unsupported Sereal version");
			}
			
			$header_size = $this->_take_varint();
			$this->header = $this->_take($header_size);
		}
		
		protected function _decode ()
		{
			$offset = $this->offset;
			$char   = strtoupper( bin2hex($this->_take(1)) );
			
			$track = false;
			if (preg_match('/^([89A-F])(.)$/', $char, $match)) {
				$track = true;
				$char  = self::$_track_replace[ $match[1] ] . $match[2];
			}
			
			if ($char == '?') {  # TAG:PAD
				return $this->_decoded();
			}
			
			# By God, isn't PHP code "beautiful"...
			$decoded = call_user_func( array($this, "_d_$char") );
			
			# Keep reference to the decoded object so that we can
			# refer to it later...
			if ($track)
				$this->offset_map[$offset] =& $decoded;
			
			return $decoded;
		}
		
		protected function _get_offset ($offset)
		{
			if ($offset >= $this->offset) {
				throw new ForwardOffset("Offset $offset requested, but it's too far ahead");
			}
			if (array_key_exists($offset, $this->offset_map)) {
				return $this->offset_map[$offset];
			}
			else {
				throw new UntrackedOffset("Offset $offset requested, but that offset didn't have the track bit set");
			}
		}
		
		protected function _take ($n)
		{
			$string = substr($this->bytes, 0, $n);
			if (strlen($string) < $n)
				throw new UnexpectedEnd("tried to read $n bytes but only found ".strlen($string));
			
			$this->bytes = substr($this->bytes, $n);
			$this->offset += $n;
			return $string;
		}
		
		protected function _take_array ($n)
		{
			$a = array();
			while ($n-->0) {
				$a[] = $this->_decode();
			}
			return $a;
		}

		protected function _take_hash ($n)
		{
			$a = array();
			while ($n-->0) {
				$k = $this->_decode();
				$v = $this->_decode();
				$a[$k] = $v;
			}
			return $a;
		}
		
		protected function _take_varint ()
		{
			list($number, $length) = varint_get($this->bytes, true);
			$this->offset += $length;
			return $number;
		}

		protected function _d_20 ()  # TAG:VARINT
		{
			return $this->_take_varint();
		}
		
		protected function _d_21 ()  # TAG:ZIGZAG
		{
			list($number, $length) = zigzag_get($this->bytes, true);
			$this->offset += $length;
			return $number;
		}
		
		protected function _d_22 ()  # TAG:FLOAT
		{
			return unpack('f', $this->_take(4));
		}
		
		protected function _d_23 ()  # TAG:DOUBLE
		{
			return unpack('d', $this->_take(8));
		}
		
		# TAG:LONG_DOUBLE - not implemented
		
		protected function _d_26 ()  # TAG:BINARY
		{
			$length = $this->_take_varint();
			return $this->_take($length);
		}
		
		protected function _d_27 ()  # TAG:STR_UTF8
		{
			$length = $this->_take_varint();
			return call_user_func($this->build_str_utf8, $this->_take($length));
		}

		protected function _d_28 ()  # TAG:REFN
		{
			$next =& $this->_decode();
			return $next;
		}

		protected function _d_29 ()  # TAG:REFP
		{
			$offset = $this->_take_varint();
			return $this->_get_offset($offset);
		}

		protected function _d_2A ()  # TAG:HASH
		{
			$length = $this->_take_varint();
			return $this->_take_hash($length);
		}

		protected function _d_2B ()  # TAG:ARRAY
		{
			$length = $this->_take_varint();
			return $this->_take_array($length);
		}

		protected function _d_2C ()  # TAG:OBJECT
		{
			$class = $this->_decode();
			$data  = $this->_decode();
			return call_user_func($this->build_object, $class, $data);
		}
		
		protected function _d_2D ()  # TAG:OBJECTV
		{
			$class_offset = $this->_take_varint();
			$class = $this->_get_offset($offset);
			$data  = $this->_decode();
			return call_user_func($this->build_object, $class, $data);
		}
		
		# TAG:ALIAS - not implemented
		
		protected function _d_2F ()  # TAG:COPY
		{
			$offset = $this->_take_varint();
			return deep_copy( $this->_get_offset($offset) );
		}
		
		protected function _d_30 ()  # TAG:WEAKEN
		{
			return call_user_func($this->build_weaken, $this->_decode());
		}
		
		protected function _d_31 ()  # TAG:REGEXP
		{
			$pattern   = $this->_decode();
			$modifiers = $this->_decode();
			return call_user_func($this->build_regexp, $pattern, $modifiers);
		}

		protected function _d_3C ()  # TAG:MANY
		{
			throw new Unimplemented("version 2 feature");
		}

		protected function _d_3D ()  # TAG:PACKET_START
		{
			throw new Malformed("unexpected PACKET_START");
		}

		protected function _d_3E ()  # TAG:EXTEND
		{
			$ext = strtoupper( bin2hex($this->_take(1)) );
			throw new Unimplemented("unimplemented exception '$ext'");
		}

		# public ... meh
		public function __call ($name, $args)
		{
			if (preg_match('/^_d_0([A-F0-9])$/', $name, $match)) {  # TAG:POS_<n>
				return hexdec( $match[1] );
			}
			if (preg_match('/^_d_1([A-F0-9])$/', $name, $match)) {  # TAG:NEG_<n>
				return hexdec( $match[1] ) - 16;
			}
			if (preg_match('/^_d_(3[2-9])$/', $name, $match)) {  # TAG:RESERVED_<n>
				throw new ReservedWord("reserved tag ${match[1]} used");
			}
			if (preg_match('/^_d_(4[A-F0-9])$/', $name, $match)) {  # TAG:ARRAYREF_<n>
				return $this->_take_array(hexdec( $match[1] ) - 64);
			}
			if (preg_match('/^_d_(5[A-F0-9])$/', $name, $match)) {  # TAG:HASHREF_<n>
				return $this->_take_hash(hexdec( $match[1] ) - 80);
			}
			if (preg_match('/^_d_([67][A-F0-9])$/', $name, $match)) {  # TAG:SHORT_BINARY_<n>
				return $this->_take(hexdec( $match[1] ) - 96);
			}
			if (preg_match('/^_d_([A-F0-9]{2})$/', $name, $match)) {
				if (array_key_exists($match[1], self::$_constants)) {
					return self::$_constants[ $match[1] ];
				}
			}
			
			throw new Unimplemented("unimplemented function $name");
		}
	}
	
}