PHP-Workers-Tutorial
====================

Git Repository with my code for the PHP Workers Tutorial

# Getting Started

Clone the repository from your command line:

```bash
git clone https://github.com/JustinCarmonyDotCom/PHP-Workers-Tutorial.git
cd PHP-Workers-Tutorial
```

Initialize & Update the submodules so that you can use the Beanstalk Console:

```bash
git submodule init
git submodule update
```

Now, we use Vagrant to setup an environment for our demo. Make sure you have
[VirtualBox](https://www.virtualbox.org/wiki/Downloads) (or another vagrant provider) and [Vagrant](http://docs.vagrantup.com/v2/installation/) installed.
Once they are installed you can go into the `vagrant` folder and `vagrant up`
the project:

 ```bash
cd vagrant
vagrant box add precise64 http://files.vagrantup.com/precise64.box
vagrant up
 ```

 If there are no errors, you should see something like this:

 ```
 [default] Running provisioner: puppet...
 Running Puppet with base.pp...
 stdin: is not a tty
 Info: Applying configuration version '1370578233'
 Notice: /Stage[main]/Redis/Package[redis-server]/ensure: ensure changed 'purged' to 'present'
 ... more install notices ...
```

