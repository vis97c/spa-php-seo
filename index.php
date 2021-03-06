<?php
require './vendor/autoload.php';
$dotenv = Dotenv\Dotenv::create('../');
$dotenv->load();
run('./library.json', './index.html');
function run($libraryPath, $htmlTemplate)
{
	$library = file_get_contents(__DIR__ . '/' . $libraryPath);
	$library = json_decode($library, true);
	$notfound = $library['_404'];
	$appData = array(
		'name' => $library['_name'],
		'title' => $library['_title'],
		'description' => $library['_description'],
		'image' => $library['_image'],
		'keywords' => $library['_keywords'],
		'twitter' => array(
			'site' => $library['_twitter']['_site'],
			'creator' => $library['_twitter']['_creator']
		)
	);
	//group dynamic routes
	$dynamicRoutes = array_reduce(
		$library['pages'],
		function ($ac, $pg) {
			if (stripos($pg['route'], ':') !== false) {
				$ac[] = $pg;
			} else {
				//check for query parameters
				if (array_key_exists('query', $pg)) {
					foreach ($pg['query'] as $queryKey => $queryParam) {
						if (stripos($pg['query'][$queryKey], ':') !== false) {
							$ac[] = $pg;
						}
					}
				}
			}
			return $ac;
		},
		[]
	);
	//group static routes
	$staticRoutes = array_reduce(
		$library['pages'],
		function ($ac, $pg) {
			if (
				stripos($pg['route'], ':') === false &&
				!array_key_exists('query', $pg)
			) {
				$ac[] = $pg;
			}

			return $ac;
		},
		[]
	);
	$isDynamic = false;
	$route = array_filter($staticRoutes, function ($route) use ($notfound) {
		//load 404 by default, replace if match if found
		return $route['route'] === $notfound;
	});
	if ($result = dispatchInStaticRoutes($staticRoutes)) {
		//check if current route matches static routes
		$route = $result;
	} elseif ($result = dispatchInDynamicRoutes($dynamicRoutes)) {
		//check if current route matches dynamic routes
		$route = $result;
		$isDynamic = true;
	}
	$route = array_values($route)[0];
	//check is route is valid
	if (
		$route['route'] === $notfound &&
		$_SERVER['REQUEST_URI'] !== $notfound
	) {
		//unregisted route, redirect
		die(header('Location: ' . $notfound));
	}
	$meta =
		isset($route['meta']) && !empty($route['meta'])
			? $route['meta']
			: $appData;
	if (!$isDynamic) {
		die(writeMeta($appData, $meta, $htmlTemplate));
	}

	//replace url shortcuts for the request propertie
	if (isset($route['meta']['_request']['url'])) {
		$protocol = getenv('APP_ENV') === 'production' ? 'https://' : 'http://';
		$route['meta']['_request']['url'] = str_replace(
			array('__API__', '__HOST__'),
			array($protocol . getenv('API'), $protocol . $_SERVER['HTTP_HOST']),
			$route['meta']['_request']['url']
		);
	}
	//replace url shortcut for the image propertie
	if (isset($route['meta']['image'])) {
		$route['meta']['image'] = str_replace(
			'__THUMBS__',
			$protocol . getenv('API_THUMBNAIL'),
			$route['meta']['image']
		);
	}
	$current = array('route' => $route['route']);
	if (isset($route['query'])) {
		$current['query'] = $route['query'];
	}
	$dynamicData = getDynamicMeta($route['meta'], $current);
	die(
		writeMeta(
			$appData,
			$dynamicData['meta'],
			$htmlTemplate,
			$dynamicData['data']
		)
	);
}
function buildQueryPath($fullPath)
{
	//get parameters from given URL
	$urlSplit = explode('?', substr($fullPath, 1));
	//mount array
	$build = array(
		'path' => explode('/', $urlSplit[0])
	);
	if (array_key_exists(1, $urlSplit)) {
		//build Query if exist
		$urlQuery = explode('&', $urlSplit[1]);
		$urlQueryResults = [];
		foreach ($urlQuery as $key) {
			$query = explode('=', $urlQuery[$key]);
			$urlQueryResults[$query[0]] = isset($query[1]) ? $query[1] : '';
		}
		$build['query'] = $urlQueryResults;
	}
	return $build;
}
function dispatchInStaticRoutes($routes)
{
	//compare current route to static ones
	$paths = array_map(function ($route) {
		return $route['route'];
	}, $routes);
	$current = explode('index', explode('?', $_SERVER['REQUEST_URI'])[0])[0];
	if (in_array($current, $paths)) {
		return array_filter($routes, function ($route) use ($current) {
			return $route['route'] === $current;
		});
	} else {
		//check if subroutes are matched
		return array_filter($routes, function ($route) use ($current) {
			$ePath = explode('*', $route['route']);
			if (count($ePath) === 2) {
				$pathString = substr($current, 0, strlen($ePath[0]));
				//check if this route matched a reserved word
				if (array_key_exists('reserved', $route)) {
					foreach ($route['reserved'] as $wordKey => $wordValue) {
						if (stripos($current, $wordValue) !== false) {
							//if there is at least one match
							return false;
						}
					}
				}
				return $ePath[0] === $pathString;
			}
		});
	}
	return false;
}
function dispatchInDynamicRoutes($routes)
{
	//compare current route to dynamic ones
	$paths = array_map(function ($route) {
		$p = buildQueryPath($route['route']);
		if (array_key_exists('reserved', $route)) {
			$p['reserved'] = $route['reserved'];
		}
		if (array_key_exists('query', $route)) {
			$p['query'] = $route['query'];
		}
		return $p;
	}, $routes);
	//get parameters from current URL
	$currentUrl = buildQueryPath($_SERVER['REQUEST_URI']);
	//compare path & query
	foreach ($paths as $path) {
		if (count($currentUrl['path']) === count($path['path'])) {
			$valid = true;
			foreach ($path['path'] as $urlKey => $urlPart) {
				//check if all parameters matches
				//looks for the use of reserved words
				if (
					(stripos($urlPart, ':') === false &&
						$urlPart !== $currentUrl['path'][$urlKey]) ||
					(array_key_exists('reserved', $path) &&
						in_array(
							$currentUrl['path'][$urlKey],
							$path['reserved']
						))
				) {
					$valid = false;
				}
			}
			if (!empty($path['query'])) {
				//path is expecting query parameters
				$valid = false;
				if (isset($currentUrl['query']) && isset($path['query'])) {
					//both exist
					foreach ($path['query'] as $urlKey => $urlPart) {
						if (array_key_exists($urlKey, $currentUrl['query'])) {
							$valid = true;
						}
					}
				}
				// there is no match, continue
			}
			if ($valid) {
				return array_filter($routes, function ($route) use ($path) {
					return buildQueryPath($route['route'])['path'] ===
						$path['path'];
				});
			}
		}
	}
	return false;
}
function writeMeta($appData, $meta, $htmlTemplate, $data = null)
{
	$file = file_get_contents(__DIR__ . '/' . $htmlTemplate);
	$replace = '';
	//fallbacks
	$title = isset($meta['title']) ? $meta['title'] : $appData['title'];
	$canonical = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
	$description = isset($meta['description'])
		? $meta['description']
		: $appData['description'];
	$image = isset($meta['image']) ? $meta['image'] : $appData['image'];
	$keywords = isset($meta['keywords'])
		? $meta['keywords']
		: $appData['keywords'];
	//title
	$replace .=
		'<meta name="apple-mobile-web-app-title" content="' .
		$appData['title'] .
		'">
		<title>' .
		$title .
		'</title>
		<link rel="canonical" href="' .
		$canonical .
		'">
		<meta name="description" content="' .
		$description .
		'">
		<meta name="keywords" content="' .
		$keywords .
		'">
		<meta property="og:title" content="' .
		$title .
		'">
		<meta property="og:url" content="' .
		$canonical .
		'">
		<meta property="og:description" content="' .
		$description .
		'">
		<meta property="og:image" content="' .
		$image .
		'">
		<meta property="og:site_name" content="' .
		$appData['name'] .
		'">
		<meta property="og:type" content="website">
		<meta name="twitter:title" content="' .
		$title .
		'">
		<meta name="twitter:description" content="' .
		$description .
		'">
		<meta name="twitter:image" content="' .
		$image .
		'">
		<meta name="twitter:card" content="summary">';

	if (!!$appData['twitter']['site']) {
		$replace .=
			'
		<meta name="twitter:site" content="' .
			$appData['twitter']['site'] .
			'">';
	}
	if (!!$appData['twitter']['creator']) {
		$replace .=
			'
		<meta name="twitter:creator" content="' .
			$appData['twitter']['creator'] .
			'">';
	}

	$initialState = array(
		"host" => $_SERVER['HTTP_HOST'],
		"meta" => $appData
	);

	if ($data !== null) {
		$initialState["data"] = $data;
	}

	$replace .=
		'
		<script>window.seo = ' .
		json_encode($initialState) .
		';</script>';
	return str_replace('<meta inject-meta-data>', $replace, $file);
}
function getRouteParams($route, $meta)
{
	//get parameters from current URL
	$url = buildQueryPath($_SERVER['REQUEST_URI']);
	$urlParams = $url['path'];
	if (array_key_exists('query', $url)) {
		$urlParams = array_merge($urlParams, $url['query']);
	}
	$routeParams = buildQueryPath($route['route'])['path'];
	if (array_key_exists('query', $route)) {
		$routeParams = array_merge($routeParams, $route['query']);
	}
	//comparar
	$result = [];
	foreach ($routeParams as $key => $value) {
		if (stripos($value, ':') !== false) {
			if (
				isset($meta['_request']['interger']) &&
				in_array($value, $meta['_request']['interger'])
			) {
				$result[$value] = (int) $urlParams[$key];
			} else {
				$result[$value] = $urlParams[$key];
			}
		}
	}
	return $result;
}
function getDynamicMeta($meta, $route)
{
	$data = isset($meta['_request']['data']) ? $meta['_request']['data'] : [];
	$params = getRouteParams($route, $meta);
	$url = array_reduce(
		array_keys($params),
		function ($ac, $ke) use ($params) {
			return str_replace($ke, $params[$ke], $ac);
		},
		$meta['_request']['url']
	);
	$curlInstance = curl_init();
	curl_setopt($curlInstance, CURLOPT_RETURNTRANSFER, 1);
	if (isset($meta['_request']['post'])) {
		curl_setopt($curlInstance, CURLOPT_POST, 1);
	} else {
		curl_setopt($curlInstance, CURLOPT_HTTPGET, 1);
	}
	if (count($data) > 0) {
		$data = array_map(function ($value) use ($params) {
			foreach ($params as $keyParam => $valueParam) {
				if ($value === $keyParam) {
					$value = $valueParam;
				}
			}
			return $value;
		}, $data);
		if (isset($meta['_request']['post'])) {
			curl_setopt(
				$curlInstance,
				CURLOPT_POSTFIELDS,
				http_build_query($data)
			);
			curl_setopt($curlInstance, CURLOPT_HTTPHEADER, array(
				'Content-Type: application/x-www-form-urlencoded'
			));
		} else {
			$url = $url . '?' . http_build_query($data);
		}
	}
	curl_setopt($curlInstance, CURLOPT_URL, $url);
	$curlReturn = curl_exec($curlInstance);
	$curlReturn = (array) json_decode($curlReturn);
	curl_close($curlInstance);
	foreach ($meta as $metaName => $metaValue) {
		if ($metaName !== '_request') {
			foreach ($curlReturn as $curlName => $curlValue) {
				$curlValue = trim($curlValue);
				$search = '$' . $curlName . '$';
				if (stripos($metaValue, $search) !== false) {
					$meta[$metaName] = str_replace(
						$search,
						$curlValue,
						$meta[$metaName]
					);
				}
			}
		}
	}
	return ['meta' => $meta, 'data' => $curlReturn];
}
