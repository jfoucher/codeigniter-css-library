<?php
/**
 * Class Minify_CSS_Compressor 
 * @package Minify
 */

/**
 * Compress CSS
 *
 * This is a heavy regex-based removal of whitespace, unnecessary
 * comments and tokens, and some CSS value minimization, where practical.
 * Many steps have been taken to avoid breaking comment-based hacks, 
 * including the ie5/mac filter (and its inversion), but expect tricky
 * hacks involving comment tokens in 'content' value strings to break
 * minimization badly. A test suite is available.
 *
 * Modified for Codeigniter by Jonathan Foucher
 * 
 * @package Minify
 * @author Stephen Clay <steve@mrclay.org>
 * @author http://code.google.com/u/1stvamp/ (Issue 64 patch)
 * @author Jonathan Foucher <jfoucher@gmail.com> http://jfoucher.com (Library for CodeIgniter)
 *
 */
class CI_Css {

    /**
     * Minify a CSS string
     * 
     * @param string $css
     * 
     * @param array $options (currently ignored)
     * 
     * @return string
     */
    /*
     * public static function process($css, $options = array())
    {
        $obj = new CI_Compressor($options);
        return $obj->_process($css);
    }
     */

    /**
     * @var Codeigniter global object
     */
    var $CI;
    var $dest_folder='';
    var $dest_file='';
    var $source_folder='';
    var $source_file='';
    var $cache_time='';

    
    /**
     * @var bool Are we "in" a hack?
     * 
     * I.e. are some browsers targeted until the next comment?
     */
    protected $_inHack = false;
    
    
    /**
     * Constructor
     * 
     * @param array $options (currently ignored)
     * 
     * @return null
     */
    public function __construct($options=array()) {
        $this->CI =& get_instance();
        if (count($options) > 0)
		{
			$this->initialize($options);
		}

		log_message('debug', "Css Class Initialized");
    }


    function link(){
        $file=$this->compress();
        return '<link rel="stylesheet" href="/'.$file.'" type="text/css" media="screen" />';
    }

    function compress(){


        //check for cache
        if (is_file($this->dest_folder.$this->dest_file) && filemtime($this->dest_folder.$this->dest_file) > time() - $this->cache_time*3600){
            return str_replace(FCPATH,'',$this->dest_folder.$this->dest_file);
        }

        //read original CSS files
        $css='';
        if (is_array($this->source_file)){
            foreach($this->source_file as $k=>$file){
                $css.=file_get_contents(str_replace(FCPATH,'',$this->source_folder[$k].$file));
            }
        }else{
            $css=file_get_contents(str_replace(FCPATH,'',$this->source_folder.$this->source_file));
        }

        //Compress
        $compressed=$this->_process($css);

        //output to file
        $f=fopen($this->dest_folder.$this->dest_file,'w+');
        @fwrite($f,$compressed);

        return str_replace(FCPATH,'',$this->dest_folder.$this->dest_file);



    }

    /**
     * Minify a CSS string
     * 
     * @param string $css
     * 
     * @return string
     */
    protected function _process($css)
    {
        $css = str_replace("\r\n", "\n", $css);
        
        // preserve empty comment after '>'
        // http://www.webdevout.net/css-hacks#in_css-selectors
        $css = preg_replace('@>/\\*\\s*\\*/@', '>/*keep*/', $css);
        
        // preserve empty comment between property and value
        // http://css-discuss.incutio.com/?page=BoxModelHack
        $css = preg_replace('@/\\*\\s*\\*/\\s*:@', '/*keep*/:', $css);
        $css = preg_replace('@:\\s*/\\*\\s*\\*/@', ':/*keep*/', $css);
        
        // apply callback to all valid comments (and strip out surrounding ws
        $css = preg_replace_callback('@\\s*/\\*([\\s\\S]*?)\\*/\\s*@'
            ,array($this, '_commentCB'), $css);

        // remove ws around { } and last semicolon in declaration block
        $css = preg_replace('/\\s*{\\s*/', '{', $css);
        $css = preg_replace('/;?\\s*}\\s*/', '}', $css);
        
        // remove ws surrounding semicolons
        $css = preg_replace('/\\s*;\\s*/', ';', $css);
        
        // remove ws around urls
        $css = preg_replace('/
                url\\(      # url(
                \\s*
                ([^\\)]+?)  # 1 = the URL (really just a bunch of non right parenthesis)
                \\s*
                \\)         # )
            /x', 'url($1)', $css);
        
        // remove ws between rules and colons
        $css = preg_replace('/
                \\s*
                ([{;])              # 1 = beginning of block or rule separator 
                \\s*
                ([\\*_]?[\\w\\-]+)  # 2 = property (and maybe IE filter)
                \\s*
                :
                \\s*
                (\\b|[#\'"-])        # 3 = first character of a value
            /x', '$1$2:$3', $css);
        
        // remove ws in selectors
        $css = preg_replace_callback('/
                (?:              # non-capture
                    \\s*
                    [^~>+,\\s]+  # selector part
                    \\s*
                    [,>+~]       # combinators
                )+
                \\s*
                [^~>+,\\s]+      # selector part
                {                # open declaration block
            /x'
            ,array($this, '_selectorsCB'), $css);
        
        // minimize hex colors
        $css = preg_replace('/([^=])#([a-f\\d])\\2([a-f\\d])\\3([a-f\\d])\\4([\\s;\\}])/i'
            , '$1#$2$3$4$5', $css);
        
        // remove spaces between font families
        $css = preg_replace_callback('/font-family:([^;}]+)([;}])/'
            ,array($this, '_fontFamilyCB'), $css);
        
        $css = preg_replace('/@import\\s+url/', '@import url', $css);
        
        // replace any ws involving newlines with a single newline
        $css = preg_replace('/[ \\t]*\\n+\\s*/', "\n", $css);
        
        // separate common descendent selectors w/ newlines (to limit line lengths)
        $css = preg_replace('/([\\w#\\.\\*]+)\\s+([\\w#\\.\\*]+){/', "$1\n$2{", $css);
        
        // Use newline after 1st numeric value (to limit line lengths).
        $css = preg_replace('/
            ((?:padding|margin|border|outline):\\d+(?:px|em)?) # 1 = prop : 1st numeric value
            \\s+
            /x'
            ,"$1\n", $css);
        
        // prevent triggering IE6 bug: http://www.crankygeek.com/ie6pebug/
        $css = preg_replace('/:first-l(etter|ine)\\{/', ':first-l$1 {', $css);
            
        return trim($css);
    }
    
    /**
     * Replace what looks like a set of selectors  
     *
     * @param array $m regex matches
     * 
     * @return string
     */
    protected function _selectorsCB($m)
    {
        // remove ws around the combinators
        return preg_replace('/\\s*([,>+~])\\s*/', '$1', $m[0]);
    }
    
    /**
     * Process a comment and return a replacement
     * 
     * @param array $m regex matches
     * 
     * @return string
     */
    protected function _commentCB($m)
    {
        $hasSurroundingWs = (trim($m[0]) !== $m[1]);
        $m = $m[1]; 
        // $m is the comment content w/o the surrounding tokens, 
        // but the return value will replace the entire comment.
        if ($m === 'keep') {
            return '/**/';
        }
        if ($m === '" "') {
            // component of http://tantek.com/CSS/Examples/midpass.html
            return '/*" "*/';
        }
        if (preg_match('@";\\}\\s*\\}/\\*\\s+@', $m)) {
            // component of http://tantek.com/CSS/Examples/midpass.html
            return '/*";}}/* */';
        }
        if ($this->_inHack) {
            // inversion: feeding only to one browser
            if (preg_match('@
                    ^/               # comment started like /*/
                    \\s*
                    (\\S[\\s\\S]+?)  # has at least some non-ws content
                    \\s*
                    /\\*             # ends like /*/ or /**/
                @x', $m, $n)) {
                // end hack mode after this comment, but preserve the hack and comment content
                $this->_inHack = false;
                return "/*/{$n[1]}/**/";
            }
        }
        if (substr($m, -1) === '\\') { // comment ends like \*/
            // begin hack mode and preserve hack
            $this->_inHack = true;
            return '/*\\*/';
        }
        if ($m !== '' && $m[0] === '/') { // comment looks like /*/ foo */
            // begin hack mode and preserve hack
            $this->_inHack = true;
            return '/*/*/';
        }
        if ($this->_inHack) {
            // a regular comment ends hack mode but should be preserved
            $this->_inHack = false;
            return '/**/';
        }
        // Issue 107: if there's any surrounding whitespace, it may be important, so 
        // replace the comment with a single space
        return $hasSurroundingWs // remove all other comments
            ? ' '
            : '';
    }
    
    /**
     * Process a font-family listing and return a replacement
     * 
     * @param array $m regex matches
     * 
     * @return string   
     */
    protected function _fontFamilyCB($m)
    {
        $m[1] = preg_replace('/
                \\s*
                (
                    "[^"]+"      # 1 = family in double qutoes
                    |\'[^\']+\'  # or 1 = family in single quotes
                    |[\\w\\-]+   # or 1 = unquoted family
                )
                \\s*
            /x', '$1', $m[1]);
        return 'font-family:' . $m[1] . $m[2];
    }


    private function _get_paths($filename, $default=false){
        $ret=array();
        if (function_exists('realpath') AND @realpath($filename) !== FALSE)
        {
            $full_path = str_replace("\\", "/", realpath($filename));
        }
        else
        {
            $full_path = $filename;
        }



        // Is there a file name?
        if ( ! preg_match("#\.(css)$#i", $full_path) && $default)
        {
            $ret['folder'] = $full_path.'/';
            $ret['file'] = $default;
        }
        else
        {
            $x = explode('/', $full_path);
            $ret['file'] = end($x);
            $ret['folder'] = str_replace($ret['file'], '', $full_path);
        }
        
        return $ret;
    }


    /**
	 * initialize compressor preferences
	 *
	 * @access	public
	 * @param	array
	 * @return	bool
	 */
	function initialize($options = array())
	{
		/*
		 * Convert array elements into class variables
		 */
		if (count($options) > 0)
		{
			foreach ($options as $key => $val)
			{
				$this->$key = $val;
			}
		}

        /*
		 * Is there a source file?
		 *
		 * If not, there's no reason to continue
		 *
		 */
        if ($this->source_file == '')
		{
			$this->set_error('compressor_source_file_required');
			return FALSE;
		}

        /*
		 * Set the full server path
		 *
		 * The source image may or may not contain a path.
		 * Either way, we'll try use realpath to generate the
		 * full server path in order to more reliably read it.
		 *
		 */
        if (is_array($this->source_file)){
            foreach($this->source_file as $k=>$file){
                $paths=$this->_get_paths($file);

                $this->source_file[$k] = $paths['file'];
                $this->source_folder[$k] = $paths['folder'];
            }
        }else{
            $paths=$this->_get_paths($this->source_file);
            $this->source_file = $paths['file'];
            $this->source_folder = $paths['folder'];
        }


        if ($this->dest_file == '')
		{
			$this->dest_file = $this->source_file;
			$this->dest_folder = $this->source_folder;
		}else{

            $paths=$this->_get_paths($this->dest_file,$this->source_file);
            $this->dest_file = $paths['file'];
            $this->dest_folder = $paths['folder'];


        }
    }

    /**
	 * Initialize compressor properties
	 *
	 * Resets values in case this class is used in a loop
	 *
	 * @access	public
	 * @return	void
	 */
	function clear()
	{
		$props = array('source_file','dest_file','cache_time');
		foreach ($props as $val)
		{
			$this->$val = '';
		}

	}
}
