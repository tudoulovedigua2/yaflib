<?php
/**
 * DB 连接封装。
 * @author fingerQin
 * @date 2018-07-13
 */

namespace finger\Database;

use finger\App;
use finger\Registry;
use finger\Exception\DbException;

class Connection
{
    /**
     * 数据库连接资源句柄。
     *
     * @var \PDO
     */
    protected $dbConnection = null;

    /**
     * 连接哪个数据库配置。对应系统配置文件 config.ini 当中 mysql.xxx.host 的 xxx
     *
     * @var string
     */
    protected $dbOption = 'default';

    /**
     *
     * @var 保存最后操作的 \PDOStatement 对象。
     */
    protected $stmt = null;

    /**
     * 当前运行的 SQL 记录。
     *
     * @var array
     */
    protected $runSqlRecords = [];

    /**
     * 当前已连接的数据库标识。
     * 
     * -- 通过这个可以检测连接心跳的时候，直接可全部重连。
     *
     * @var array
     */
    protected static $connectedIdent = [];

    /**
     * 是否开启事务。
     *
     * @var bool
     */
    protected static $transactionStatus = false;

    /**
     * 构造方法。
     *
     * @param  string  $dbOption  数据库配置项。
     * @return void
     */
    public function __construct($dbOption = '')
    {
        if (strlen($dbOption) > 0) {
            $this->dbOption = $dbOption;
            $this->changeDb($this->dbOption);
        }
    }

    /**
     * 切换数据库连接。
     *
     * @param  string  $dbOption  数据库配置项。
     * @return void
     */
    final public function changeDb($dbOption)
    {
        $registryName = "mysql_{$dbOption}";
        if (Registry::has($registryName) === false) {
            $this->connection($dbOption);
        }
        $this->dbConnection = Registry::get($registryName);
    }

    /**
     * 返回真实的数据库对象。
     * @return PDO
     */
    final public function getDbClient()
    {
        return $this->dbConnection;
    }

    /**
     * 连接数据库。
     *
     * @param  string  $dbOption  数据库配置项。
     * @return void
     */
    final public function connection($dbOption = '')
    {
        if (strlen($dbOption) > 0) {
            $this->dbOption = $dbOption;
        }
        $registryName = "mysql_{$this->dbOption}";
        // [1] 传统初始化MySQL方式。
        $config = App::getDbConfig();
        if (!isset($config[$dbOption])) {
            throw new DbException("MySQL 配置：{$dbOption} 未设置");
        }
        $config   = $config[$dbOption];
        $host     = $config['host'];
        $port     = $config['port'];
        $username = $config['user'];
        $password = $config['pwd'];
        $charset  = $config['charset'];
        $dbname   = $config['dbname'];
        $pconnect = $config['pconnect'];
        $dsn      = "mysql:dbname={$dbname};host={$host};port={$port}";
        $dbh      = new \PDO($dsn, $username, $password, [\PDO::ATTR_PERSISTENT => $pconnect]);
        // MySQL操作出错，抛出异常。
        $dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $dbh->setAttribute(\PDO::ATTR_ORACLE_NULLS, \PDO::NULL_NATURAL);
        $dbh->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, FALSE);
        $dbh->setAttribute(\PDO::ATTR_EMULATE_PREPARES, FALSE);
        // 以关联数组返回查询结果。
        $dbh->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $dbh->query("SET NAMES {$charset}");
        self::$connectedIdent[$registryName] = $dbOption; // 之所以以连接标识做键,是避免多次连接导致持续的增加。
        Registry::set($registryName, $dbh);
    }

    /**
     * 获取当前 MySQL 连接的标识。
     *
     * @return void
     */
    final public static function getConnectedIdent()
    {
        return self::$connectedIdent;
    }

    /**
     * 关闭数据库连接。
     *
     * @param  string  $dbOption  数据库选项标识。空字符串关闭所有链接。
     *
     * @return void
     */
    final public function close($dbOption = '')
    {
        if (strlen($dbOption) == 0) {
            $dbOption = $this->dbOption;
        }
        // [1] 取配置选项。
        $dbOptions = [];
        if (strlen($dbOption) > 0) {
            $dbOptions[] = $dbOption;
        } else {
            $mysqlConfigs = App::getDbConfig();
            foreach($mysqlConfigs as $dbOption => $config) {
                $dbOptions[] = $dbOption;
            }
        }
        // [2] 根据选项关闭数据库连接。
        foreach($dbOptions as $dbOption) {
            $registryName = "mysql_{$dbOption}";
            if (Registry::has($registryName) === true) {
                $dbh = Registry::get($registryName);
                $dbh = null;
                Registry::set($registryName, null);
            }
        }
    }

    /**
     * 数据库重连。
     *
     * @param  string  $dbOption  数据库配置项。断线重连时，以哪个数据库配置重连。
     * 
     * @return void
     */
    final public function reconnect($dbOption = '')
    {
        if (strlen($dbOption) == 0) {
            $dbOption = $this->dbOption;
        }
        $registryName = "mysql_{$dbOption}";
        $this->connection($dbOption);
        $this->dbConnection = Registry::get($registryName);
    }

    /**
     * 检查连接是否可用(类似于http ping)。
     * 
     * -- 向 MySQL 服务器发送获取服务器信息的请求。
     * 
     * @param  int     $isReconnect  当与 MySQL 服务器的连接不可用时,是否重连。默认断线重连。
     * @param  string  $dbOption     数据库配置项。断线重连时，以哪个数据库配置重连。
     * 
     * @return void
     * @throws \finger\Exception\DbException
     */
    final public function ping($isReconnect = true, $dbOption = '')
    {
        if (strlen($dbOption) == 0) {
            $dbOption = $this->dbOption;
        }
        if (!$this->dbConnection) {
            throw new DbException('Please connect to the database correctly!');
        }
        try {
            $info = $this->dbConnection->getAttribute(\PDO::ATTR_SERVER_INFO);
            if (is_null($info)) {
                if ($isReconnect && !self::$transactionStatus) {
                    $this->reconnect($dbOption);
                    return true;
                }
            } else {
                return true;
            }
        } catch (\PDOException $e) {
            $mysqlGoneAwayErrMsg = 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away';
            if ($isReconnect && !self::$transactionStatus && stripos($e->getMessage(), $mysqlGoneAwayErrMsg) !== FALSE) {
                App::log("reconnect:{$dbOption}", 'errors', 'mysql-ping');
                $this->reconnect($dbOption);
                return true;
            } else {
                return false;
            }
        }
    }

    /**
     * 对当前已激活的 MySQL 连接进行存活心路检测。
     * 
     * @param  bool  $isReconnect  是否重连。
     *
     * @return void
     */
    public static function allPing($isReconnect = true)
    {
        foreach (self::$connectedIdent as $dbOption) {
            $registryName = "mysql_{$dbOption}";
            if (Registry::has($registryName) === true) {
                $dbh = Registry::get($registryName);
                try {
                    $info = $dbh->getAttribute(\PDO::ATTR_SERVER_INFO);
                    if (is_null($info)) {
                        if ($isReconnect && !self::$transactionStatus) {
                            (new self)->reconnect($dbOption);
                        } else {
                            throw new DbException('The database server is disconnected!');
                        }
                    }
                } catch (\PDOException $e) {
                    $mysqlGoneAwayErrMsg = 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away';
                    if ($isReconnect && !self::$transactionStatus && stripos($e->getMessage(), $mysqlGoneAwayErrMsg) !== FALSE) {
                        App::log("reconnect:{$dbOption}", 'mysql', 'ping');
                        (new self)->reconnect($dbOption);
                    } else {
                        throw new DbException('The database server is disconnected!');
                    }
                }
            }
        }
    }

    /**
     * 开启数据库事务。
     */
    final public function beginTransaction()
    {
        $isActive = $this->dbConnection->inTransaction();
        if (!$isActive) {
            $bool = $this->dbConnection->beginTransaction();
            if (!$bool) {
                $this->openTransactionFailed();
            }
        }
        self::$transactionStatus = true;
    }

    /**
     * 提交数据库事务。
     */
    final public function commit()
    {
        $isActive = $this->dbConnection->inTransaction();
        if ($isActive) {
            $bool = $this->dbConnection->commit();
            if (!$bool) {
                $this->commitTransactionFailed();
            }
        }
        self::$transactionStatus = false;
    }

    /**
     * 回滚数据库事务。
     */
    final public function rollBack()
    {
        $isActive = $this->dbConnection->inTransaction();
        if ($isActive) {
            $bool = $this->dbConnection->rollBack();
            if (!$bool) {
                $this->rollbackTransactionFailed();
            }
        }
        self::$transactionStatus = false;
    }

    /**
     * 事务开启失败。
     * @return void
     */
    protected function openTransactionFailed()
    {
        throw new DbException('Open transaction failure');
    }

    /**
     * 提交事务失败。
     * @return void
     */
    protected function commitTransactionFailed()
    {
        throw new DbException('Transaction commit failure');
    }

    /**
     * 提交事务失败。
     * @return void
     */
    protected function rollbackTransactionFailed()
    {
        throw new DbException('Transaction rollback failed');
    }

    /**
     * 记录 SQL 日志。
     * 
     * -- 正式环境不记录执行的 SQL
     *
     * @param  string  $sql     执行的 SQL。
     * @param  array   $params  SQL 参数。
     * @return void
     */
    final public function writeSqlLog($sql, $params = [])
    {
        if (App::isDebug()) {
            foreach ($params as $key => $val) {
                $val = "'" . addslashes($val) . "'";
                $sql = str_replace("{$key},", "{$val},", $sql);
                $sql = str_replace("{$key})", "{$val})", $sql);
            }
            $this->pushRunSqlRecords($sql);
        }
    }

    /**
     * 返回当前已执行的 SQL 记录。
     *
     * @return array
     */
    final public function getRunSqlRecords()
    {
        return $this->runSqlRecords;
    }

    /**
     * 记录执行的 SQL。
     *
     * @param  string  $sql  SQL 语句。
     * @return void
     */
    final public function pushRunSqlRecords($sql)
    {
        array_push($this->runSqlRecords, $sql);
    }

    /**
     * 析构方法。
     * -- 处理日志。
     */
    public function __destruct()
    {
        if (App::isDebug()) {
            App::log($this->runSqlRecords, 'mysql', 'log');
        }
    }
}