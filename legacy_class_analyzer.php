<?php
/**
 * 旧类引用分析工具
 * 
 * 分析项目中旧类的引用情况，确定哪些类可以安全删除
 */

class LegacyClassAnalyzer {
    
    private $projectRoot;
    private $legacyClasses = [
        'Smart_Cache',
        'Session_Cache', 
        'Content_Converter',
        'Database_Helper',
        'Database_Index_Manager',
        'Database_Index_Optimizer',
        'Unified_Concurrency_Manager',
        'Memory_Manager' // 原始类，不是适配器
    ];
    
    public function __construct($projectRoot = __DIR__) {
        $this->projectRoot = $projectRoot;
    }
    
    /**
     * 分析所有旧类的引用情况
     */
    public function analyze() {
        echo "=== 旧类引用分析 ===\n\n";
        
        $results = [];
        
        foreach ($this->legacyClasses as $className) {
            echo "🔍 分析类: {$className}\n";
            $analysis = $this->analyzeClass($className);
            $results[$className] = $analysis;
            
            $this->printClassAnalysis($className, $analysis);
            echo "\n";
        }
        
        $this->printSummary($results);
        return $results;
    }
    
    /**
     * 分析单个类的引用情况
     */
    private function analyzeClass($className) {
        $phpFiles = $this->getAllPhpFiles();
        $references = [];
        $canDelete = true;
        $hasReplacement = false;
        
        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            $fileReferences = $this->findReferences($content, $className, $file);
            
            if (!empty($fileReferences)) {
                $references[$file] = $fileReferences;
                
                // 检查是否是适配器文件或测试文件
                if (!$this->isIgnorableFile($file)) {
                    $canDelete = false;
                }
            }
        }
        
        // 检查是否有替代品
        $hasReplacement = $this->hasReplacement($className);
        
        return [
            'references' => $references,
            'reference_count' => count($references),
            'can_delete' => $canDelete,
            'has_replacement' => $hasReplacement,
            'file_exists' => $this->classFileExists($className)
        ];
    }
    
    /**
     * 查找类引用
     */
    private function findReferences($content, $className, $file) {
        $references = [];
        $lines = explode("\n", $content);
        
        foreach ($lines as $lineNum => $line) {
            // 检查各种引用模式
            $patterns = [
                'use_statement' => "/use\s+.*\\\\{$className};/",
                'new_instance' => "/new\s+\\\\?.*{$className}\s*\(/",
                'static_call' => "/\\\\?.*{$className}::/",
                'instanceof' => "/instanceof\s+\\\\?.*{$className}/",
                'type_hint' => "/\s{$className}\s+\$/",
                'string_reference' => "/['\"].*{$className}.*['\"]/",
                'class_reference' => "/\b{$className}\b/"
            ];
            
            foreach ($patterns as $type => $pattern) {
                if (preg_match($pattern, $line)) {
                    $references[] = [
                        'line' => $lineNum + 1,
                        'type' => $type,
                        'content' => trim($line)
                    ];
                }
            }
        }
        
        return $references;
    }
    
    /**
     * 检查文件是否可以忽略
     */
    private function isIgnorableFile($file) {
        $ignorablePatterns = [
            '/test_.*\.php$/',
            '/.*_test\.php$/',
            '/.*Test\.php$/',
            '/.*_adapter\.php$/i',
            '/.*Adapter\.php$/',
            '/legacy_class_analyzer\.php$/',
            '/naming_.*\.php$/'
        ];
        
        foreach ($ignorablePatterns as $pattern) {
            if (preg_match($pattern, basename($file))) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查类是否有替代品
     */
    private function hasReplacement($className) {
        $replacements = [
            'Smart_Cache' => 'CacheManager',
            'Session_Cache' => 'CacheManager',
            'Content_Converter' => 'ContentProcessingService',
            'Database_Helper' => 'DatabaseManager',
            'Database_Index_Manager' => 'DatabaseManager',
            'Database_Index_Optimizer' => 'DatabaseManager',
            'Unified_Concurrency_Manager' => 'ConcurrencyManager',
            'Memory_Manager' => 'MemoryMonitor + StreamProcessor + BatchOptimizer + GarbageCollector'
        ];
        
        return isset($replacements[$className]);
    }
    
    /**
     * 检查类文件是否存在
     */
    private function classFileExists($className) {
        $possiblePaths = [
            "includes/utils/{$className}.php",
            "includes/core/{$className}.php",
            "includes/services/{$className}.php",
            "includes/handlers/{$className}.php"
        ];
        
        foreach ($possiblePaths as $path) {
            if (file_exists($this->projectRoot . '/' . $path)) {
                return $this->projectRoot . '/' . $path;
            }
        }
        
        return false;
    }
    
    /**
     * 获取所有PHP文件
     */
    private function getAllPhpFiles() {
        $files = [];
        $directories = ['includes', 'admin'];
        
        foreach ($directories as $dir) {
            $fullPath = $this->projectRoot . '/' . $dir;
            if (is_dir($fullPath)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($fullPath)
                );
                
                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getExtension() === 'php') {
                        $files[] = $file->getPathname();
                    }
                }
            }
        }
        
        return $files;
    }
    
    /**
     * 打印单个类的分析结果
     */
    private function printClassAnalysis($className, $analysis) {
        echo "  📊 引用统计: {$analysis['reference_count']} 个文件\n";
        echo "  📁 文件存在: " . ($analysis['file_exists'] ? "✅ " . basename($analysis['file_exists']) : "❌ 不存在") . "\n";
        echo "  🔄 有替代品: " . ($analysis['has_replacement'] ? "✅ 是" : "❌ 否") . "\n";
        echo "  🗑️  可删除: " . ($analysis['can_delete'] ? "✅ 是" : "❌ 否") . "\n";
        
        if ($analysis['reference_count'] > 0) {
            echo "  📋 引用详情:\n";
            foreach ($analysis['references'] as $file => $refs) {
                $fileName = basename($file);
                $isIgnorable = $this->isIgnorableFile($file);
                $status = $isIgnorable ? "🟡 可忽略" : "🔴 需处理";
                
                echo "    - {$fileName} ({$status}): " . count($refs) . " 处引用\n";
                
                // 显示前3个引用
                $displayRefs = array_slice($refs, 0, 3);
                foreach ($displayRefs as $ref) {
                    echo "      L{$ref['line']}: {$ref['type']} - " . substr($ref['content'], 0, 60) . "...\n";
                }
                
                if (count($refs) > 3) {
                    echo "      ... 还有 " . (count($refs) - 3) . " 处引用\n";
                }
            }
        }
    }
    
    /**
     * 打印总结报告
     */
    private function printSummary($results) {
        echo "=== 分析总结 ===\n\n";
        
        $canDelete = [];
        $needsAttention = [];
        $hasReplacements = 0;
        
        foreach ($results as $className => $analysis) {
            if ($analysis['has_replacement']) {
                $hasReplacements++;
            }
            
            if ($analysis['can_delete']) {
                $canDelete[] = $className;
            } else {
                $needsAttention[] = $className;
            }
        }
        
        echo "📊 统计信息:\n";
        echo "  - 分析的类: " . count($this->legacyClasses) . " 个\n";
        echo "  - 有替代品: {$hasReplacements} 个\n";
        echo "  - 可安全删除: " . count($canDelete) . " 个\n";
        echo "  - 需要注意: " . count($needsAttention) . " 个\n\n";
        
        if (!empty($canDelete)) {
            echo "✅ 可安全删除的类:\n";
            foreach ($canDelete as $className) {
                echo "  - {$className}\n";
            }
            echo "\n";
        }
        
        if (!empty($needsAttention)) {
            echo "⚠️  需要注意的类:\n";
            foreach ($needsAttention as $className) {
                echo "  - {$className}\n";
            }
            echo "\n";
        }
        
        echo "💡 建议:\n";
        echo "  1. 优先删除可安全删除的类文件\n";
        echo "  2. 对需要注意的类，先更新引用再删除\n";
        echo "  3. 保留适配器文件以维持向后兼容性\n";
        echo "  4. 删除前备份重要文件\n";
    }
}

// 命令行执行
if (php_sapi_name() === 'cli') {
    $analyzer = new LegacyClassAnalyzer(__DIR__);
    $analyzer->analyze();
}
