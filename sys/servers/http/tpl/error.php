<!doctype html>
<html>
<head>
    <title><?php echo $title ?></title>
    <style>
        body{margin:20;padding:20px;text-align: left; border:1px solid #CECECE;}
        .error {font-size:18px; border-bottom:  1px dashed #CECECE;}
        .file {font-size: 18px;}
        div.trace {font-size: 18px;}
        pre.trace{font-size:14px; background-color: #f9f9f9; padding:20px; border: 1px solid #CECECE;}
    </style>
</head>

<body>
<div class="error"><span id="errtype_node"></span>: <?php echo $message ?></div>
<div class="file">文件: <?php echo $file ?></div>
<div class="line">行号: <?php echo $line ?></div>
<div class="trace">调用栈: </div>
<pre class="trace"><?php echo $trace ?></pre>
</body>
<script lang="javascript">
    var errtype = "<?php echo $type ?>";
    let dict = {
        Error:'错误', Exception:'异常', ErrorException:'错误异常', ArgumentCountError:'参数数目错误', ArithmeticError:'数学运算错误', AssertionError:'断言错误', DivisionByZeroError:'被零除',
        CompileError:'编译错误', ParseError:'语法解析错误', TypeError:'声明与数据不匹配', ValueError:'非参数预期值', UnhandledMatchError:'未知错误',FiberError:'协程错误',
        'sys\\exception\\MemcachedException':'Memcached 异常',
        'sys\\exception\\CrontabException':'计划任务 异常',
    }
    let err = dict[errtype] ?? errtype;
    document.getElementById('errtype_node').innerText = err;
</script>
</html>
