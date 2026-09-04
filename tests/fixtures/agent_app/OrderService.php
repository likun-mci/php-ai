<?php
namespace AgentAppFixture;

/**
 * 订单业务 Service —— 高风险 / 强制确认 的标注示例
 */
class OrderService
{
    /**
     * 订单退款
     *
     * @agent-tool order.refund
     * @agent-description 对指定订单发起退款，金额不能超过订单实付
     * @agent-controller order/refund
     * @agent-risk high
     * @agent-permission order/refund
     * @agent-keywords 订单,退款,退钱
     *
     * @param int $orderId 订单 ID
     * @param float $amount 退款金额
     * @param string|null $reason 退款原因
     * @return array 退款结果
     */
    public function refund($orderId, $amount, $reason = null)
    {
        return ['order_id' => $orderId, 'amount' => $amount, 'reason' => $reason, 'ok' => true];
    }

    /**
     * 清空历史订单
     *
     * @agent-tool order.purge
     * @agent-description 物理删除指定日期之前的全部订单，操作不可逆
     * @agent-controller order/purge
     * @agent-risk critical
     * @agent-keywords 订单,清理,删除,危险
     *
     * @param string $before 日期，格式 YYYY-MM-DD
     * @return array 清理结果
     */
    public function purge($before)
    {
        return ['purged_before' => $before];
    }

    /**
     * 缺 @agent-controller 的 Tool —— 索引时会记 error，执行时会被拒绝
     *
     * @agent-tool order.orphan
     * @agent-description 没有映射 Controller 入口的能力
     * @agent-risk low
     *
     * @return array
     */
    public function orphan()
    {
        return ['ok' => true];
    }
}
