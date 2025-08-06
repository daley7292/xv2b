<div style="background: linear-gradient(135deg, #a5f3fc, #3b82f6); padding: 60px 0; font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="
                    background-color: #ffffff;
                    border-radius: 16px;
                    border: 6px solid #ffffff;
                    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
                    overflow: hidden;
                ">
                    <tr>
                        <td style="background: linear-gradient(90deg, #0ea5e9, #14b8a6); color: #ffffff; padding: 28px 40px; font-size: 24px; font-weight: 600;">
                            {{ $name }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 40px 40px 0 40px; font-size: 26px; font-weight: bold; color: #0f172a; text-align: center;">
                            ⏰ 到期通知
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px 40px 32px 40px; font-size: 15px; color: #334155; line-height: 1.8;">
                            尊敬的用户您好！
                            <br><br>
                            你的服务将在 24 小时内到期。为了不造成使用上的影响请尽快续费。<br>
                            如果你已续费，请忽略此邮件。
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: #ecfeff; padding: 20px 40px; text-align: center; font-size: 13px; color: #6b7280;">
                            官网：{{ rtrim(str_replace(['https://', 'http://'], '', $url), '/') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</div>
