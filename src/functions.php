<?php

namespace Bugcache;

use Aerys;

function jsonify(callable $handler) {
	return new class($handler) implements Aerys\Bootable {
		private $handler;
		function __construct($handler) {
			$this->handler = $handler;
		}

		function boot(Aerys\Server $server, Aerys\Logger $logger) {
			$handler = $this->handler;
			if (is_array($handler) && is_object($handler[0])) {
				$handler = $handler[0];
			}
			if ($handler instanceof Aerys\Bootable) {
				$handler->boot($server, $logger);
			}
		}
		
		function __invoke(Aerys\Request $req, Aerys\Response $res, $routerData = []) {
			$data = ($this->handler)($req, $routerData);

			if ($data instanceof \Generator) {
				$data = yield from $data;
			}

			$res->setHeader("content-type", "application/json");
			if ($data === null) {
				$res->setStatus(403);
				$res->end('{"error": "invalid request"}');
			} else {
				$res->end(json_encode($data));
			}
		}
	};
}