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
                    transition: all 0.3s ease-in-out;
                ">
                    <tr>
                        <td style="background: linear-gradient(90deg, #0ea5e9, #14b8a6); color: #ffffff; padding: 28px 40px; font-size: 24px; font-weight: 600; letter-spacing: 1px;">
                            {{ $name }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 40px 40px 0 40px; text-align: center; font-size: 28px; color: #0f172a; font-weight: bold;">
                            🔐 验证您的邮箱
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px 40px; font-size: 16px; color: #334155; line-height: 1.8;">
                            尊敬的用户您好，<br><br>
                            以下是您的邮箱验证码，请在 <strong>5 分钟内</strong> 输入以完成验证。
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding: 12px 40px 32px 40px;">
                            <div style="display: inline-block; padding: 18px 28px; background: #0f172a; color: #ffffff; font-size: 26px; font-weight: bold; letter-spacing: 4px; border-radius: 10px; box-shadow: 0 0 12px rgba(0,0,0,0.2);">
                                {{ $code }}
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 0 40px 32px 40px; font-size: 14px; color: #94a3b8;">
                            如果您并未请求此验证码，请忽略此邮件。
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
