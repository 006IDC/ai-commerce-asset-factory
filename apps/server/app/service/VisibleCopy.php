<?php

declare(strict_types=1);

namespace app\service;

/**
 * Audience-safe copy mapping.
 *
 * Raw provider, transport and execution diagnostics stay in administrator
 * records. Merchant surfaces receive only the outcome, point handling and a
 * useful next action.
 */
final class VisibleCopy
{
    public static function merchantProgress(mixed $value): string
    {
        $message = trim((string) $value);
        if ($message === '') {
            return '';
        }

        return str_replace(
            [
                '等待 Worker 处理',
                '等待视频 Worker',
                'Worker 已恢复任务',
                'Worker 心跳中断',
                '等待管理员配置可用模型渠道',
                '等待管理员配置 ComfyUI 工作流引擎',
                'ComfyUI 工作流模板尚未通过测试',
            ],
            [
                '等待后台处理',
                '等待后台处理',
                '后台已恢复任务',
                '后台处理暂时中断',
                '当前生成能力暂不可用',
                '当前生成能力暂不可用',
                '当前生成能力暂不可用',
            ],
            $message
        );
    }

    public static function merchantGenerationError(mixed $value): string
    {
        $message = trim((string) $value);
        if ($message === '') {
            return '';
        }

        $lower = mb_strtolower($message);
        if (str_contains($lower, '余额不足或无可用资源包')
            || str_contains($lower, 'insufficient balance')
            || str_contains($lower, 'insufficient quota')
            || str_contains($lower, 'quota exceeded')) {
            return '当前模型服务的上游资源不足，本次处理未完成。任务资料和预扣点数会按原规则保留或退回；请等待管理员恢复模型资源后，在原任务手动重试。';
        }
        if (str_contains($lower, '额度不足') || str_contains($lower, '点数不足')) {
            return '可用点数不足，任务尚未开始。请补充点数或开通合适的套餐后重新提交。';
        }
        if (str_contains($lower, '安全策略') || str_contains($lower, '图片被上游拦截')
            || str_contains($lower, 'safety policy') || str_contains($lower, 'content policy')
            || str_contains($lower, 'policy violation') || str_contains($lower, 'moderation blocked')) {
            return '参考图或描述触发了生成服务的安全限制，本次未产出可用图片且不会扣费。请保留原商品档案，调整可能引发误判的描述或参考图后，直接在原任务重试，无需重新建档。';
        }
        if (str_contains($lower, '质量检查两次都')
            || str_contains($lower, '发布前质量检查暂不可用')) {
            return '图片已生成，但发布前质量检查暂未完成。系统只重试了质量检查，没有重复生图；本次不扣费。请保留原商品档案，稍后在原任务重试。';
        }
        if (str_contains($lower, '原图') || str_contains($lower, '未修改') || str_contains($lower, 'unchanged reference')) {
            return '结果与参考图没有形成有效变化，本次未产出可用图片。请补充更明确的修改要求后重试。';
        }
        if (str_contains($message, 'HTTP 405') || str_contains($lower, 'method not allowed')
            || ((str_contains($lower, 'images/edits') || str_contains($lower, 'image edit'))
                && (str_contains($message, '404') || str_contains($lower, 'not supported')))) {
            return '当前模型暂不支持这类参考图编辑，本次未产出可用结果。请选择支持参考图的模型后重新提交。';
        }
        if (str_contains($message, 'HTTP 429') || str_contains($lower, 'status code 429')
            || str_contains($lower, 'too many requests') || str_contains($lower, 'rate limit')
            || str_contains($lower, 'concurrency limit') || str_contains($message, '请求过多')) {
            return '当前模型服务请求较多，本次处理未完成。这不是内容违规，任务资料和预扣点数会按原规则保留或退回；请稍后在原任务手动重试。';
        }
        if (str_contains($message, 'HTTP 504') || str_contains($message, 'HTTP 524')
            || str_contains($lower, 'timed out') || str_contains($lower, 'cURL error 28')
            || str_contains($message, '当前连接不稳定')) {
            return '本次生成处理超时，没有产出可用结果。预扣点数会按原规则退回，请稍后手动重试。';
        }
        if (str_contains($message, 'HTTP 502') || str_contains($message, 'HTTP 503')
            || str_contains($lower, 'bad gateway') || str_contains($lower, 'service unavailable')
            || str_contains($lower, 'connection refused') || str_contains($lower, 'could not resolve host')
            || str_contains($lower, 'invalid token') || str_contains($lower, 'status code: 401')
            || str_contains($message, 'No available channel for model')
            || str_contains($lower, 'no available account')
            || str_contains($lower, 'service temporarily unavailable')
            || str_contains($lower, 'not supported when using codex')) {
            return '当前模型服务暂时不可用，本次处理未完成。这不是内容违规，任务资料和预扣点数会按原规则保留或退回；请稍后在原任务手动重试。';
        }
        if (str_contains($lower, '内容安全服务暂时不可用')
            || str_contains($lower, '内容安全模型返回格式无法识别')
            || str_contains($lower, '内容安全模型未配置')
            || str_contains($lower, '内容安全检查失败')
            || str_contains($lower, '图片安全检查失败')
            || str_contains($lower, '图片安全检查子进程异常')
            || str_contains($lower, '审核服务暂时不可用')) {
            return '当前内容审核服务暂时不可用，本次处理未完成。这不是内容违规，任务资料和预扣点数会按原规则保留或退回；请稍后在原任务手动重试。';
        }
        if (str_contains($lower, '内容安全检测未通过')
            || str_contains($lower, '内容安全审核未通过')
            || str_contains($lower, '命中平台违禁词')
            || str_contains($lower, 'content safety violation')) {
            return '本次内容未通过安全检查，任务没有进入生成。请调整文字或参考图后重新提交。';
        }

        return '生成服务返回了异常，本次未产出可用结果。任务参数已保留，预扣点数会按原规则退回；请稍后手动重试。';
    }
}
