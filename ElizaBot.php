<?php

require "Util.php";
require "ElizaConfigs.php";

class ElizaBot
{
	public $_dataParsed = false;

	protected $noRandom;
	protected $capitalizeFirstLetter = true;
	protected $debug = false;
	protected $memSize = 20;
	protected $version = "1.1 (original)";
	protected $quit;
	protected $mem = [];
	protected $lastChoice = [];
	protected $pres = [];
	protected $posts = [];
	protected $preExp;

	function ElizaBot($noRandomFlag=false) {
		Util::echoln("construct ElizaBot");

		$this->noRandom = ($noRandomFlag) ? true : false;
		$this->capitalizeFirstLetter = true;
		$this->debug = false;
		$this->memSize = 20;
		if(!$this->_dataParsed)
			$this->_init();
		$this->reset();
	}

	function __destruct() {
		Util::echoln("destruct ElizaBot");
	}

	function reset() {
		Util::echoln("called reset()");

		global $elizaKeywords;

		$this->quit = false;
		$this->mem = [];
		$this->lastChoice = [];
		for($k=0; $k<count($elizaKeywords); $k++)
		{
			$this->lastChoice[$k] = [];
			$rules = $elizaKeywords[$k][2];
			for($i=0; $i<count($rules); $i++)
				$this->lastChoice[$k][$i] = -1;
		}
	}

	function _init() {
		Util::echoln("called _init()");

		global $elizaSynons;
		global $elizaKeywords;
		global $elizaPres;
		global $elizaPosts;
		global $elizaQuits;

		// parse data and convert it from canonical form to internal use
		// prodoce synonym list
		$synPatterns = [];
		if( $elizaSynons && is_array($elizaSynons) ) {
			foreach($elizaSynons as $key => $arrayValues)
				$synPatterns[$key] = '('.$key.'|'.join('|', $arrayValues).')';
		}

		// check for keywords or install empty structure to prevent any errors
		if(!$elizaKeywords) {
			$elizaKeywords = [['###',0,[['###',[]]]]];
		}
		// 1st convert rules to regexps
		// expand synonyms and insert asterisk expressions for backtracking
		$sre='/@(\S+)/';
		$are='/(\S)\s*\*\s*(\S)/';
		$are1='/^\s*\*\s*(\S)/';
		$are2='/(\S)\s*\*\s*$/';
		$are3='/^\s*\*\s*$/';
		$wsre='/\s+/g';

		for($k=0; $k<count($elizaKeywords); $k++)
		{
			$rules = $elizaKeywords[$k][2];
			$elizaKeywords[$k][3] = $k;	// save original index for sorting
			for($i=0; $i<count($rules); $i++)
			{
				$r = $rules[$i];
				// check mem flag and store it as decomp's elements 2
				if($r[0][0] == '$')
				{
					$ofs = 1;
					while($r[0][$ofs] == ' ')
						$ofs++;
					$r[0] = substr($r[0], $ofs);
					$r[2] = true;
				}
				else
				{
					$r[2] = false;
				}

				// expand synonyms (v.1.1: work around lambda function)
				preg_match($sre, $r[0], $m, PREG_OFFSET_CAPTURE);
				while($m)
				{
					// consult https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/RegExp/exec for documentation on this section.
					$sp = $synPatterns[$m[1][0]] ? $synPatterns[$m[1][0]] : $m[1][0];
					$r[0] = substr($r[0], 0, $m[0][1]-1).$sp.substr($r[0], $m[0][1] + strlen($m[0][0]));
					preg_match($sre, $r[0], $m, PREG_OFFSET_CAPTURE);
				}
				// expand asterisk expressions (v.1.1: work around lambda function)
				if(preg_match($are3, $r[0]))
				{
					$r[0] = '\\s*(.*)\s*';
				}
				else
				{
					preg_match($are, $r[0], $m, PREG_OFFSET_CAPTURE);
					if($m)
					{
						$lp = '';
						$rp = $r[0];
						while($m)
						{
							$lp .= substr($rp, 0, $m[0][1]);
							if ($m[1][0] != ')')
								$lp .= '\\b';
							$lp .= '\\s*(.*)\\s*';
							if (($m[2][0] != '(') && ($m[2][0] != '\\'))
								$lp .= '\\b';
							$lp .= $m[2][0];
							$rp = substr($rp, $m[0][1] + strlen($m[0][0]));
							preg_match($are, $rp, $m, PREG_OFFSET_CAPTURE);
						}
						$r[0] = $lp.$rp;
					}
					preg_match($are1, $r[0], $m, PREG_OFFSET_CAPTURE);
					if($m)
					{
						$lp = '\\s*(.*)\\s*';
						if (($m[1][0] != ')') && ($m[1][0] != '\\'))
							$lp .= '\\b';
						$r[0] = $lp.substr($r[0], $m[0][1]-1+strlen($m[0][0]));
					}
					preg_match($are2, $r[0], $m, PREG_OFFSET_CAPTURE);
					if(m)
					{
						$lp = substr($r[0], 0, $m[0][1]);
						if ($m[1][0] != '(')
							$lp .= '\\b';
						$r[0] = $lp.'\\s*(.*)\\s*';
					}
				}
				// expand white space
				$r[0] = preg_replace($wsre, '\\s+', $r[0]);
			}
		}
		// now sort keywords by rank (highest first)
		sort($elizaKeywords, "self::_sortKeywords");
		// and compose regexps and refs for pres and posts
		if($elizaPres && count($elizaPres))
		{
			$a = [];
			for($i = 0; $i < count($elizaPres); $i+=2)
			{
				$a[] = $elizaPres[i];
				$this->pres[$elizaPres[$i]] = $elizaPres[$i+1];
			}
			$this->preExp = '\\b('.join('|', $a).')\\b';
		}
		else
		{
			// default (should not match)
			$this->preExp = '/####/';
			$this->pres['####'] = '####';
		}
		if($elizaPosts && count($elizaPosts))
		{
			$a = [];
			for($i=0; $i<count($elizaPosts); $i+=2)
			{
				$a[] = $elizaPosts[i];
				$this->posts[$elizaPosts[i]] = $elizaPosts[i+1];
			}
			$this->postExp = '\\b('.join('|', $a).')\\b';
		}
		else
		{
			// default (should not match)
			$this->postExp = '/####/';
			$this->posts['####'] = '####';
		}
		// check for elizaQuits and install default if missing
		if (!isset($elizaQuits))
		{
			$elizaQuits = [];
		}
		// done
		$this->_dataParsed = true;
	}

	function _sortKeywords($a, $b) {
		// sort by rank
		if($a[1] > $b[1])
			return -1;
		else if($a[1] < $b[1])
			return 1;
		// or original index
		else if($a[3] > $b[3])
			return 1;
		else if($a[3] < $b[3])
			return -1;
		else
			return 0;
	}

	function _getRuleIndexByKey($key)
	{
		global $elizaKeywords;

		for($k=0; $k < count($elizaKeywords); $k++)
		{
			if($elizaKeywords[$k][0] == $key)
				return $k;
		}

		return -1;
	}

	function _memSave($t)
	{
		$this->mem[] = $t;
		if(count($this->mem) > $this->memSize)
			array_shift($this->mem);
	}

	function _memGet()
	{
		if(count($this->mem))
		{
			if($this->noRandom)
				return array_shift($this->mem);
			else
			{
				$n = floor(Util::randomFloat() * count($this->mem));
				$rpl = $this->mem[$n];
				for($i=$n+1; $i<count($this->mem); $i++)
					$this->mem[$i-1] = $this->mem[$i];
				array_pop($this->mem);
				return $rpl;
			}
		}
		else
			return '';
	}

	function getFinal()
	{
		global $elizaFinals;

		return $elizaFinals[floor(Util::randomFloat() * count($elizaFinals))];
	}

	function getInitial() 
	{
		global $elizaInitials;

		return $elizaInitials[floor(Util::randomFloat() * count($elizaInitials))];
	}
}
























?>