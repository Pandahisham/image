<?php namespace KevBaldwyn\Image;

use KevBaldwyn\Image\Providers\ProviderInterface;
use KevBaldwyn\Image\Servers\Cache as CacheServer;
use KevBaldwyn\Image\Servers\ImageCow as ImageCowServer;
use Imagecow\Image as ImageCow;
use Closure;

class Image {

	private $provider;
	private $cacheLifetime; // minutes

	private $pathStringbase = '';
	private $pathString;

	private $callbacks = array();

	private $server;

	const EVENT_ON_CREATED = 'kevbaldwyn.image.created';
	const CALLBACK_MODIFY_IMG_PATH = 'callback.modifyImgPath';

	public function __construct(ProviderInterface $provider, $cacheLifetime, $serveRoute) {
		$this->provider       = $provider;
		$this->cacheLifetime  = $cacheLifetime;
		$this->pathStringBase = $serveRoute;
	}


	public function responsive(/* any number of params */) {
		$params = func_get_args();
		if(count($params) <= 1) {
			throw new \Exception('Not enough params provided to generate a responsive image');
		}
		
		list($rule, $transform) = $this->getPathOptions($params);

		// write out the reposinsive url part
		$this->pathString .= ';' . $rule . ':' . $transform . '&' . $this->provider->getVarResponsiveFlag() . '=true';
		return $this;
	}


	public function getBasePath()
	{
		$basePath = $this->pathStringBase;
		return $basePath . '?';
	}


	public function getImagePath()
	{
		$imgPath = $this->provider->publicPath() . $this->provider->getQueryStringData($this->provider->getVarImage());
		if(array_key_exists(self::CALLBACK_MODIFY_IMG_PATH, $this->callbacks)) {
			foreach($this->callbacks[self::CALLBACK_MODIFY_IMG_PATH] as $callback) {
				$imgPath = $callback($imgPath);
			}
		}
		return $imgPath;
	}


	public function path(/* any number of params */) {
		
		$params = func_get_args();
		if(count($params) <= 1) {
			throw new \Exception('Not enough params provided to generate an image');
		}
		
		list($img, $transform) = $this->getPathOptions($params);

		// write out the resize path
		$this->pathString = $this->getBasePath();
		$this->pathString .= $this->provider->getVarImage() . '=' . $img;
		$this->pathString .= '&' . $this->provider->getVarTransform() . '=' . $transform;
		return $this;
	}


	public function getImageData()
	{
		$server = $this->getServer();
		return $server->getImageData();
	}


	public function isFromCache()
	{
		return $this->getServer()->isFromCache();
	}


	public function serve() {

		$server = $this->getServer();

		if(!$server->isFromCache()) {
			$server->create();

			//$this->provider->fireEvent(self::EVENT_ON_CREATED, array($imgPath, $server->getWorker(), $this->getOperations()));
		}	

		$server->serve();
		
	}


	private function getServer()
	{
		if(is_null($this->server)) {
			// get the tarnsformations
			$operations = $this->getOperations();
			
			// get the image path
			$imgPath   = $this->getImagePath();

			// check cache
			$checksum  = md5($imgPath . ';' . serialize($operations));
			$cacheData = $this->provider->getFromCache($checksum);
			
			// get the correctly instantiated server object
			if($cacheData) {
				$this->server = new CacheServer($cacheData);
			}else{
				$worker = Imagecow::create($imgPath, $this->provider->getWorkerName());
				$this->server = new ImageCowServer(
					$worker, 
					$operations,
					$this->provider, 
					$this->cacheLifetime,
					$checksum
				);
			}
		}

		return $this->server;
	}


	public function addCallback($type, Closure $callback)
	{
		$this->callbacks[$type][] = $callback;
	}


	public function js($publicDir = '/public') {
		
		$jsFile = $this->provider->getJsPath();

		// hacky hack hack
		// if .js file doesn't exist in defined location then copy it there?! (or throw an error?)
		if(!file_exists($this->provider->basePath() . $jsFile)) {
			throw new \Exception('Javascript file does not exists! Please copy /vendor/imagecow/imagecow/Imagecow/Imagecow.js to ' . $jsFile);
		}

		// check if the path starts with "public"
		// if so then we need to remove it 
		// - the file_exists is checking the server path not the web path
		// will this always be the case?
		//$path = (preg_match('/^\/?public\//', $jsFile)) ? str_replace('public/', '', $jsFile) : $jsFile;
		
		// nicer to pass it through as a param instead:
		$path = (!is_null($publicDir)) ? str_replace($publicDir, '', $jsFile) : $jsFile;

		$str  = '
		<script src="' . $path . '" type="text/javascript" charset="utf-8"></script>
		<script type="text/javascript">
    		Imagecow.init();
		</script>';

		return $str;

	}


	public function __toString() {
		return $this->pathString;
	}


	private function getOperations()
	{
		if($this->provider->getQueryStringData($this->provider->getVarResponsiveFlag()) == 'true') {
			$operations = Imagecow::getResponsiveOperations($_COOKIE['Imagecow_detection'], $this->provider->getQueryStringData($this->provider->getVarTransform()));
		}else{
			$operations = $this->provider->getQueryStringData($this->provider->getVarTransform());
		}
		return $operations;
	}


	private function getPathOptions($params) {

		$first = $params[0];

		foreach($params as $key => $param) {
			if($key > 0) {
				$transformA[] = $param;
			}
		}
		$transform = implode(',', $transformA);

		return array($first, $transform);

	}

}