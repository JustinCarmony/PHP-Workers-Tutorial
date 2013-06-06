Exec {
    path => [ '/bin/', '/sbin/' , '/usr/bin/', '/usr/sbin/' ]
}

class apt-get-update {
  exec { 'apt-get update':
    command => '/usr/bin/apt-get update'
  }
}

$core_tools_packages = ["build-essential", "imagemagick", "sshfs", "git-core", "subversion", "htop", "vim", "curl", "nano", "screen"]

class core-tools {
  package { $core_tools_packages: ensure => "installed", require => Exec["apt-get update"] }
  file { "/usr/local/bin/solo":
    path => "/usr/local/bin/solo",
    source => '/puppet-files/bin/solo',
    mode => 755
  }

  file { "/root/.bash_aliases":
    path => "/root/.bash_aliases",
    source => '/puppet-files/root/.bash_aliases',
    mode => 700
  }

  # Add PPAs
  apt::ppa { "ppa:ondrej/php5": }
}

class apache2 {
  package { "apache2":
    ensure => present,
    require => Class["core-tools"]
  }

  exec { "a2enmod rewrite":
    unless => "ls /etc/apache2/mods-enabled/rewrite.load",
    command => "a2enmod rewrite",
    notify => Service["apache2"],
    require => Package["apache2"]
  }
  service { "apache2":
    ensure => running,
    require => Package["apache2"],
  }

  # Setup apache config files
  file { "/etc/apache2/sites-available/default" :
    path => "/etc/apache2/sites-available/default",
    source => '/puppet-files/etc/apache2/sites-available/default',
    require => Package["apache2"],
    notify => Service["apache2"]
  }
}

$php5_packages = ["php5", "libapache2-mod-php5", "php5-cli","php5-dev", "php-pear", "php5-curl","php5-imagick","php5-memcache","php5-mysql","php5-xdebug"]

class php5 { 
  
  package { $php5_packages: ensure => "latest", require => Class["core-tools"], notify => Service["apache2"] }
  
  exec { "install composer":
    unless => "ls /usr/local/bin/composer",
    command => '/bin/sh -c "cd /tmp && curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer && chmod +x /usr/local/bin/composer"',
    require => Package[$php5_packages]
  }
}

class beanstalkd {
  package { "beanstalkd":
    ensure => installed,
  }
  service { "beanstalkd":
      enable => true,
    ensure => running,
    #hasrestart => true,
    #hasstatus => true,
    require => Package["beanstalkd"]
  }
  file { "/etc/default/beanstalkd":
    ensure => file,
    source => '/puppet-files/etc/default/beanstalkd',
    notify => Service["beanstalkd"]
  }
}

class redis {
  package { "redis-server":
    ensure => installed,
  }
  service { "redis-server":
      enable => true,
    ensure => running,
    #hasrestart => true,
    #hasstatus => true,
    require => Package["redis-server"]
  }
  file { "/etc/redis/redis.conf":
    ensure => file,
    source => '/puppet-files/etc/redis/redis.conf'
  }
}

class workers {
  require php5, beanstalkd, redis

  # Log Directory
  file { "/var/log/worker":
    ensure => "directory",
}

  cron { "worker 3":
    command  => "/usr/local/bin/solo -port=5001 /usr/bin/php /mnt/source/cli/worker.php 3 >> /var/log/worker_log_3.log",
    user     => "root",
    month    => "*",
    monthday => "*",
    hour     => "*",
    minute   => "*",
  }

  cron { "worker 4":
    command  => "/usr/local/bin/solo -port=5002 /usr/bin/php /mnt/source/cli/worker.php 4 >> /var/log/worker_log_4.log",
    user     => "root",
    month    => "*",
    monthday => "*",
    hour     => "*",
    minute   => "*",
  }
}

class supervisor {
  require workers
  package { "supervisor":
    ensure => installed,
  }

  service { "supervisor":
      enable => true,
    ensure => running,
    #hasrestart => true,
    #hasstatus => true,
    require => Package["supervisor"],
  }

  file { "/etc/supervisor/supervisord.conf":
    ensure => file,
    source => '/puppet-files/etc/supervisor/supervisord.conf',
    notify => Service['supervisor']
  }
}

include apt-get-update
include core-tools
include apache2
include php5
include beanstalkd
include redis
include workers
include supervisor