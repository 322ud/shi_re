<?php

declare(strict_types=1);

namespace OCA\ShiRE\AppInfo;

use OCP\AppFramework\App;

class Application extends App {
  public const APP_ID = 'shi_re';

  public function __construct() {
    parent::__construct(self::APP_ID);
  }
}
