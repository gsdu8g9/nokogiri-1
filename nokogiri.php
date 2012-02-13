<?php

/**
 * Simple HTML parser
 *
 * @author olamedia <olamedia@gmail.com>
 */
class nokogiri implements IteratorAggregate{
	const
	regexp = 
	"/(?P<tag>[a-z0-9]+)?(\[(?P<attr>\S+)=(?P<value>\S+)\])?(#(?P<id>\S+))?(\.(?P<class>[^\s:>#\.]+))?(:(?P<pseudo>(first|last|nth)-child(\([^\)]+)\))))?\s*(?P<rel>>)?/isS"
	;
	protected $_source = '';
	/**
	 * @var DOMDocument
	 */
	protected $_dom = null;
	/**
	 * @var DOMDocument
	 */
	protected $_tempDom = null;
	/**
	 * @var DOMXpath
	 * */
	protected $_xpath = null;
	public function __construct($htmlString = ''){
		$this->loadHtml($htmlString);
	}
	public function getRegexp(){
		$tag = "(?P<tag>[a-z0-9]+)?";
		$attr = "(\[(?P<attr>\S+)=(?P<value>[^\]]+)\])?";
		$id = "(#(?P<id>[^\s:>#\.]+))?";
		$class = "(\.(?P<class>[^\s:>#\.]+))?";
		$child = "(first|last|nth)-child";
		$expr = "(\((?P<expr>[^\)]+)\))";
		$pseudo = "(:(?P<pseudo>".$child.")".$expr.")?";
		$rel = "\s*(?P<rel>>)?";
		$regexp = "/".$tag.$attr.$id.$class.$pseudo.$rel."/isS";
		return $regexp;
	}
	public static function fromHtml($htmlString){
		$me = new self();
		$me->loadHtml($htmlString);
		return $me;
	}
	public static function fromDom($dom){
		$me = new self();
		$me->loadDom($dom);
		return $me;
	}
	public function loadDom($dom){
		$this->_dom = $dom;
	}
	public function loadHtml($htmlString = ''){
		$dom = new DOMDocument('1.0', 'UTF-8');
		$dom->preserveWhiteSpace = false;
		if (strlen($htmlString)){
			libxml_use_internal_errors(TRUE);
			$dom->loadHTML($htmlString);
			libxml_clear_errors();
		}
		$this->loadDom($dom);
	}
	function __invoke($expression){
		return $this->get($expression);
	}
	public function get($expression){
		/*if (strpos($expression, ' ') !== false){
			$a = explode(' ', $expression);
			foreach ($a as $k=>$sub){
				$a[$k] = $this->getXpathSubquery($sub);
			}
			return $this->getElements(implode('', $a));
		}*/
		return $this->getElements($this->getXpathSubquery($expression));
	}
	protected function getNodes(){

	}
	protected function getDom(){
		if ($this->_dom instanceof DOMDocument){
			return $this->_dom;
		}elseif ($this->_dom instanceof DOMNodeList){
			if ($this->_tempDom === null){
				$this->_tempDom = new DOMDocument('1.0', 'UTF-8');
				$root = $this->_tempDom->createElement('root');
				$this->_tempDom->appendChild($root);
				foreach ($this->_dom as $domElement){
					$domNode = $this->_tempDom->importNode($domElement, true);
					$root->appendChild($domNode);
				}
			}
			return $this->_tempDom;
		}
	}
	protected function getXpath(){
		if ($this->_xpath === null){
			$this->_xpath = new DOMXpath($this->getDom());
		}
		return $this->_xpath;
	}
	public function getXpathSubquery($expression, $rel = false){
		$query = '';
		if (preg_match($this->getRegexp(), $expression, $subs)){
			$tag = isset($subs['tag']) && !empty($subs['tag'])?$subs['tag']:'*';
			$query = ($rel?'/':'//').$tag;
			//var_dump($subs);
			$brackets = array();
			if ('' !== $subs['id']){
				$brackets[] = "@id='".$subs['id']."'";
			}
			if ('' !== $subs['attr']){
				$attrValue = isset($subs['value']) && !empty($subs['value'])?$subs['value']:'';
				$brackets[] = "@".$subs['attr']."='".$attrValue."'";
			}
			if (isset($subs['class']) && '' !== $subs['class']){
				$brackets[] = 'contains(concat(" ", normalize-space(@class), " "), " '.$subs['class'].' ")';
			}
			if ('' !== $subs['pseudo']){
				if ('first-child' === $subs['pseudo']){
					$brackets[] = '1';
				}elseif ('last-child' === $subs['pseudo']){
					$brackets[] = 'last()';
				}elseif ('nth-child' === $subs['pseudo']){
					if (isset($subs['expr']) && '' !== $subs['expr']){
						$e = $subs['expr'];
						if('odd' === $e){
							$brackets[] = '(position() -1) mod 2 = 0 and position() >= 1';
						}elseif('even' === $e){
							$brackets[] = 'position() mod 2 = 0 and position() >= 0';
						}elseif(preg_match("/^((?P<mul>[0-9]+)n\+)(?P<pos>[0-9]+)$/is", $e, $esubs)){
							if (isset($esubs['mul'])){
								$brackets[] = '(position() -'.$esubs['pos'].') mod '.$esubs['mul'].' = 0 and position() >= '.$esubs['pos'].'';
							}else{
								$brackets[] = ''.$e.'';
							}
						}
					}
				}
			}
			if ($c = count($brackets)){
				if ($c > 1){
					$query .= '[('.implode(') and (', $brackets).')]';
				}else{
					$query .= '['.implode(' and ', $brackets).']';
				}
			}
			$left = trim(substr($expression, strlen($subs[0])));
			if ('' !== $left){
				$query .= $this->getXpathSubquery($left, '>'===$subs['rel']);
			}
		}
		return $query;
	}
	protected function getElements($xpathQuery){
		if (strlen($xpathQuery)){
			$nodeList = $this->getXpath()->query($xpathQuery);
			if ($nodeList === false){
				throw new Exception('Malformed xpath');
			}
			return self::fromDom($nodeList);
		}
	}
	public function toXml(){
		return $this->getDom()->saveXML();
	}
	public function toArray($xnode = null){
		$array = array();
		if ($xnode === null){
			if ($this->_dom instanceof DOMNodeList){
				foreach ($this->_dom as $node){
					$array[] = $this->toArray($node);
				}
				return $array;
			}
			$node = $this->getDom();
		}else{
			$node = $xnode;
		}
		if (in_array($node->nodeType, array(XML_TEXT_NODE,XML_COMMENT_NODE))){
			return $node->nodeValue;
		}
		if ($node->hasAttributes()){
			foreach ($node->attributes as $attr){
				$array[$attr->nodeName] = $attr->nodeValue;
			}
		}
		if ($node->hasChildNodes()){
			if ($node->childNodes->length == 1){
				$array[$node->firstChild->nodeName] = $this->toArray($node->firstChild);
			}else{
				foreach ($node->childNodes as $childNode){
					$array[$childNode->nodeName][] = $this->toArray($childNode);
				}
			}
		}
		if ($xnode === null){
			return reset(reset($array)); // first child
		}
		return $array;
	}
	public function getIterator(){
		$a = $this->toArray();
		return new ArrayIterator($a);
	}
}


/*$saw = new nokogiri();
echo $saw->getXpathSubquery('#boo #ge > #id:nth-child(3n+5)');
echo "\r\n";*/



