<?php
#
# Markdown Extra Math - PHP Markdown Extra with additional syntax for jsMath equations
# Copyright (c) 2008-2009 Dr. Drang
# <http://www.leancrew.com/all-this/>
#
# Markdown Extra  -  A text-to-HTML conversion tool for web writers
#
# PHP Markdown & Extra
# Copyright (c) 2004-2012 Michel Fortin  
# <http://michelf.com/projects/php-markdown/>
#
# Original Markdown
# Copyright (c) 2004-2006 John Gruber  
# <http://daringfireball.net/projects/markdown/>
#
namespace{
	if(defined('MARKDOWN_PARSER_CLASS') === false){
		define('MARKDOWN_PARSER_CLASS', 'Markdown\Parser');
	}

	interface iMarkdown_Parser{
		public static function r(array $config);
		public function transform($text);
	}

	function Markdown($text, array $config){
		static $parser;
		if(isset($parser) === true){
			if(class_exists(MARKDOWN_PARSER_CLASS) === false){
				throw new RuntimeException('The defined parser class \'' . MARKDOWN_PARSER_CLASS . '\' does not exist.');
			}else{
				try{
					$reflection = new ReflectionClass(MARKDOWN_PARSER_CLASS);
				}catch(ReflectionException $e){
					throw new RuntimeException('Failed to build reflection class with exception code ' . $e->getCode() . '.');
				}
				if($reflection->implementsInterface('iMarkdown_Parser') === false){
					throw new RuntimeException('The defined parser class \'' . MARKDOWN_PARSER_CLASS . '\' does not implement iMarkdown_Parser');
				}
				$parser = call_user_func_array(MARKDOWN_PARSER_CLASS . '::r', $config);
			}
		}
		return $parser->transform($text);
	}
}

namespace Markdown{

	abstract class ParserCommon implements iMarkdown_Parser{
		const VERSION = '1.0.1o';  # Sun 8 Jan 2012

		private static $em_strong_prepared_relist;
		const em_regex_1        = ' (?:(?<!\*)\*(?!\*)|(?<!_)_(?!_))(?=\S|$)(?![\.,:;]\s)';
		const em_regex_2        = '* (?<=\S|^)(?<!\*)\*(?!\*)';
		const em_regex_3        = '_ (?<=\S|^)(?<!_)_(?!_)';
		const strong_regex_1    = ' (?:(?<!\*)\*\*(?!\*)|(?<!_)__(?!_))(?=\S|$)(?![\.,:;]\s)';
		const strong_regex_2    = '** (?<=\S|^)(?<!\*)\*\*(?!\*)';
		const strong_regex_3    = '__ (?<=\S|^)(?<!\*)\*\*(?!\*)';
		const em_strong_regex_1 = ' (?:(?<!\*)\*\*\*(?!\*)|(?<!_)___(?!_))(?=\S|$)(?![\.,:;]\s)';
		const em_strong_regex_2 = '*** (?<=\S|^)(?<!\*)\*\*\*(?!\*)';
		const em_strong_regex_3 = '___ (?<=\S|^)(?<!_)___(?!_)';

		private $nested_brackets_re;
		private $nested_url_parenthesis_re;

		const escape_chars = '\`*_{}[]()>#+-.!';
		private $escape_chars_re;


		private static $document_gamut;
		# Strip link definitions, store in hashes.
		const document_gamut_stripLinkDefinitions = 20;

		const document_gamut_runBasicBlockGamut   = 30;


		private static $block_gamut;
		#
		# These are all the transformations that form block-level
		# tags like paragraphs, headers, and list items.
		#
		const block_gamut_doHeaders         = 10;
		const block_gamut_doHorizontalRules = 20;
		const block_gamut_doLists           = 40;
		const block_gamut_doCodeBlocks      = 50;
		const block_gamut_doBlockQuotes     = 60;


		private static $span_gamut;
		# Process character escapes, code spans, math, and inline HTML
		# in one shot.
		const span_gamut_parseSpan           = -30;

		# Process anchor and image tags. Images must come first,
		# because ![foo][f] looks like an anchor.
		const span_gamut_doImages            = 10;
		const span_gamut_doAnchors           = 20;
		
		# Make links out of things like `<http://example.com/>`
		# Must come after doAnchors, because you can use < and >
		# delimiters in inline links like [this](<url>).
		const span_gamut_doAutoLinks         = 30;
		const span_gamut_encodeAmpsAndAngles = 40;

		const span_gamut_doItalicsAndBold    = 50;
		const span_gamut_doHardBreaks        = 60;


		private $config;

		protected function __construct(array $config){
			static::defaultValue($config['EMPTY_ELEMENT_SUFFIX'        ], ' />'                   );
			static::defaultValue($config['TAB_WIDTH'                   ], 4                       );
			static::defaultValue($config['FN_LINK_TITLE'               ], 'Go to footnote %%'     );
			static::defaultValue($config['FN_BACKLINK_TITLE'           ], 'Go back to footnote %%');
			static::defaultValue($config['FN_LINK_CLASS'               ]                          );
			static::defaultValue($config['FN_BACKLINK_CLASS'           ]                          );
			static::defaultValue($config['MATH_TYPE'                   ], 'mathjax'               );
			static::defaultValue($config['nested_brackets_depth'       ], 6                       );
			static::defaultValue($config['nested_url_parenthesis_depth'], 4                       );
			static::defaultValue($config['urls'                        ], array()                 );
			static::defaultValue($config['titles'                      ], array()                 );

			if(isset(static::$em_strong_prepared_relist, static::$document_gamut, static::$block_gamut, static::$span_gamut) === false){
				$reflection    = new ReflectionClass($this);
				$constants     = $reflection->getConstants();

				$em_relist        = array();
				$strong_relist    = array();
				$em_strong_relist = array();

				$relist = array(
					'em'        => array(),
					'strong'    => array(),
					'em_strong' => array()
				);
				$gamut = array(
					'document_gamut' => array(),
					'block_gamut'    => array()
				);

				foreach($constants as $constant=>$v){
					if(preg_match('/^(em|strong|em_strong)_regex_[\d]+$/', $constant, $matches) == 1){
						$pos = strpos($v, ' ');
						if($pos !== false){
							$relist[$matches[1]][trim(substr($v, 0, $pos + 1))] = substr($v, $pos + 1);
						}
					}else if(preg_match('/^(document_gamut|block_gamut|span_gamut)_([A-z_][A-z0-9_]*)$/', $constant, $matches) == 1){
						$gamut[[$matches[1]][$matches[2]] = (integer)$v;
					}
				}

				$em_relist                 = $relist['em'       ];
				$strong_relist             = $relist['strong'   ];
				$em_strong_relist          = $relist['em_strong'];
				$em_strong_prepared_relist = array();

				foreach ($em_relist as $em => $em_re) {
					foreach ($strong_relist as $strong => $strong_re) {
						# Construct list of allowed token expressions.
						$token_relist = array();
						if (isset($em_strong_relist[$em . $strong])) {
							$token_relist[] = $em_strong_relist[$em . $strong];
						}
						$token_relist[] = $em_re;
						$token_relist[] = $strong_re;
						
						# Construct master expression from list.
						$token_re = '{('. implode('|', $token_relist) .')}';
						$em_strong_prepared_relist[$em . $strong] = $token_re;
					}
				}
				
				static::$em_strong_prepared_relist = $em_strong_prepared_relist;
				static::$document_gamut            = $gamut['document_gamut']  ;
				static::$block_gamut               = $gamut['block_gamut']     ;
				static::$span_gamut                = $gamut['span_gamut']     ;
				
				unset($em_relist, $strong_relist, $em_strong_relist, $em_strong_prepared_relist, $gamut);

				asort(static::$document_gamut);
				asort(static::$block_gamut   );
				asort(static::$span_gamut    );
			}

			$this->nested_brackets_re        =
				str_repeat('(?>[^\[\]]+|\[', $config['nested_brackets_depth']).
				str_repeat('\])*'          , $config['nested_brackets_depth'])
			;
			$this->nested_url_parenthesis_re =
				str_repeat('(?>[^()\s]+|\(', $config['nested_url_parenthesis_depth']).
				str_repeat('(?>\)))*'      , $config['nested_url_parenthesis_depth'])
			;
			$this->escape_chars_re           = '['.preg_quote($this->escape_chars).']';

			$this->config                    = $config;
		}

		protected static final function defaultValue(& $property=null, $value=''){
			$property = isset($property) ? $property : $value;
		}

		protected static function utf8_strlen($string){
			static $func = function_exists('mb_strlen') ? function($text){
				return mb_strlen($text, 'UTF-8');
			} : function($text){
				return preg_match_all("/[\\\\x00-\\\\xBF]|[\\\\xC0-\\\\xFF][\\\\x80-\\\\xBF]*/", $text, $m);
			};
			return $func($string);
		}


		public final function transform($text){
			$this->startup();
			$text = $this->doTransform($text);
			$this->teardown();
			return $text . "\n";
		}

		private $urls;
		private $titles;
		private $html_hashes;
		private $in_anchor = false;

		protected function startup(){
			$this->urls   = $this->config['urls'];
			$this->titles = $this->config['titles'];
			$this->html_hashes = array();
			$this->in_anchor = false;
		}

		protected function doTransform($text){
			# Remove UTF-8 BOM and marker character in input, if present.
			$text = preg_replace('{^\xEF\xBB\xBF|\x1A}', '', $text);

			# Standardize line endings:
			#   DOS to Unix and Mac to Unix
			$text = preg_replace('{\r\n?}', "\n", $text);

			# Make sure $text ends with a couple of newlines:
			$text .= "\n\n";

			# Convert all tabs to spaces.
			$text = $this->detab($text);

			# Turn block-level HTML blocks into hash entries
			$text = $this->hashHTMLBlocks($text);

			# Strip any lines consisting only of spaces and tabs.
			# This makes subsequent regexen easier to write, because we can
			# match consecutive blank lines with /\n+/ instead of something
			# contorted like /[ ]*\n+/ .
			$text = preg_replace('/^[ ]+$/m', '', $text);

			foreach(static::$document_gamut as $method=>$priority){
				$text = call_user_func_array(array($this, $method), array($text));
			}
		}

		protected function teardown(){
			$this->urls = null;
			$this->titles = null;
			$this->html_hashes = null;
		}
	}

	class Parser implements iMarkdown_Parser{



		protected function __construct(array $config){
			parent::__construct($config);
		}


		public static r(array $config){
			static $registry = array();
			$hash = md5(print_r($config, true));
			if(isset($registry[$hash]) === false){
				$registry[$hash] = new static($config);
			}
			return $registry[$hash];
		}
	}
}
?>