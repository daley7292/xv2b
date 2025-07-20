<div style="background: linear-gradient(135deg, #415A94, #1e3a8a); padding: 50px 0; font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 12px; box-shadow: 0 12px 30px rgba(0,0,0,0.15); overflow: hidden;">
                    
                    <tr>
                        <td style="background: linear-gradient(90deg, #415A94, #1e40af); color: #ffffff; padding: 28px 40px; font-size: 24px; font-weight: 600; letter-spacing: 1px;">
                            {{ $name }}
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 40px 40px 0 40px; text-align: center; font-size: 28px; color: #111827; font-weight: bold;">
                            ✨ 验证您的邮箱
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 24px 40px; font-size: 16px; color: #4B5563; line-height: 1.8;">
                            尊敬的用户您好：<br><br>
                            以下是您的邮箱验证码，请在 <strong>5 分钟内</strong> 输入以完成验证。
                        </td>
                    </tr>

                    <tr>
                        <td align="center" style="padding: 12px 40px 32px 40px;">
                            <div style="display: inline-block; padding: 18px 28px; background: #111827; color: #ffffff; font-size: 26px; font-weight: bold; letter-spacing: 4px; border-radius: 8px; box-shadow: 0 0 12px rgba(0,0,0,0.2);">
                                {{ $code }}
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 0 40px 32px 40px; font-size: 14px; color: #9CA3AF;">
                            如果您并未请求此验证码，请忽略此邮件。
                        </td>
                    </tr>

                    <tr>
                        <td style="background-color: #F3F4F6; padding: 20px 40px; text-align: center; font-size: 13px; color: #6B7280;">
                            官网：{{ $url }}
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</div>
