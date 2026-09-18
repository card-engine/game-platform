export type LegalType = 'privacy' | 'terms'

interface LegalDocument {
  title: string
  updated: string
  intro: string
  sections: Array<{ title: string; paragraphs: string[] }>
}

const en: Record<LegalType, LegalDocument> = {
  terms: {
    title: 'User Agreement',
    updated: 'September 18, 2026',
    intro: 'This agreement governs access to MGames, including its game lobby, wallet, recharge features and third-party game services.',
    sections: [
      { title: '1. Acceptance and eligibility', paragraphs: ['By using MGames you accept this agreement and confirm that you meet the legal age and eligibility requirements that apply where you live. Do not use the service where it is prohibited.'] },
      { title: '2. Browser account', paragraphs: ['MGames automatically creates an account for this browser. The browser credential is your means of access. You are responsible for protecting the device and browser data. Clearing browser storage or changing devices may make the account inaccessible.'] },
      { title: '3. Game services', paragraphs: ['Game availability, rules, outcomes and supported currencies vary by provider. Do not manipulate games, automate play, exploit errors or interfere with the service or other users.'] },
      { title: '4. Wallet and recharge', paragraphs: ['Displayed balances are platform records. When paying with TRX or USDT, use the specified TRON address, currency and exact amount. Blockchain transfers are irreversible. Network fees are separate, and payments sent on the wrong network or with incorrect information may require manual review.'] },
      { title: '5. Third-party services', paragraphs: ['MGames connects to game providers, blockchain networks and infrastructure services. Their availability and processing time may affect the service. Links to third-party services do not transfer responsibility for your use of those services to MGames.'] },
      { title: '6. Errors and interruptions', paragraphs: ['MGames may suspend a game, recharge or account operation while investigating errors, duplicate transactions, provider failures or security risks. Incorrectly displayed data may be corrected using the underlying transaction records.'] },
      { title: '7. Intellectual property', paragraphs: ['MGames software, branding and interface are protected by applicable intellectual-property laws. Game names and assets may belong to their respective providers.'] },
      { title: '8. Responsibility', paragraphs: ['Use the service responsibly and keep independent records of important transfers. To the extent permitted by applicable law, MGames is not responsible for indirect loss caused by network failure, third-party interruption, incompatible devices or unauthorized access resulting from failure to protect your browser account.'] },
      { title: '9. Changes and contact', paragraphs: ['This agreement may be updated when the service or legal requirements change. The current version is published on this page. Contact MGames through its published support channel if you have questions.'] },
    ],
  },
  privacy: {
    title: 'Privacy Policy',
    updated: 'September 18, 2026',
    intro: 'This policy explains what MGames collects, why it is used and the choices available to you.',
    sections: [
      { title: '1. Information collected', paragraphs: ['MGames may process your browser credential hash, user number, nickname, language, IP address, device and browser information, service logs, game activity, wallet records, recharge orders and blockchain transaction data.'] },
      { title: '2. How information is used', paragraphs: ['Information is used to create and protect your account, launch games, maintain balances, process recharge orders, provide support, prevent fraud, diagnose failures and comply with applicable obligations.'] },
      { title: '3. Browser storage', paragraphs: ['MGames uses local browser storage to remember your account credential, language, theme and lobby state. Removing this storage resets those settings and may make the browser account inaccessible.'] },
      { title: '4. Service providers', paragraphs: ['Necessary information may be shared with contracted game providers, blockchain services, hosting, monitoring and support providers. They receive only the information needed to perform their service.'] },
      { title: '5. Retention', paragraphs: ['Account, transaction and security records are kept for as long as needed to operate the service, resolve disputes, prevent abuse and meet applicable legal or accounting requirements.'] },
      { title: '6. Security', paragraphs: ['MGames uses access controls, credential hashing, transport security and operational monitoring. No internet service can guarantee absolute security, so you should also protect your device and browser.'] },
      { title: '7. Your choices', paragraphs: ['You may request access to or correction of supported profile information and ask about account or data deletion through a published MGames support channel. Some transaction and security records may need to be retained.'] },
      { title: '8. Minors and international processing', paragraphs: ['MGames is not intended for anyone below the applicable legal age. Information may be processed in countries where MGames or its service providers operate, subject to applicable safeguards.'] },
      { title: '9. Changes and contact', paragraphs: ['The current policy is published on this page. Contact MGames through its published support channel for privacy questions.'] },
    ],
  },
}

const zh: Record<LegalType, LegalDocument> = {
  terms: {
    title: '用户协议',
    updated: '2026 年 9 月 18 日',
    intro: '本协议适用于 MGames 游戏大厅、钱包、充值以及第三方游戏服务。',
    sections: [
      { title: '一、接受协议与使用资格', paragraphs: ['使用 MGames 即表示你接受本协议，并确认已达到所在地法律要求的年龄和资格。所在地禁止使用本服务时，请勿继续访问。'] },
      { title: '二、浏览器账号', paragraphs: ['MGames 会为当前浏览器自动创建账号，浏览器凭证是访问账号的依据。你需要妥善保管设备和浏览器数据；清除浏览器数据或更换设备后，原账号可能无法恢复。'] },
      { title: '三、游戏服务', paragraphs: ['游戏是否可用、游戏规则、结果和支持币种由具体游戏平台决定。禁止篡改游戏、自动化操作、利用错误或干扰平台及其他用户。'] },
      { title: '四、钱包与充值', paragraphs: ['页面余额以平台账务记录为准。使用 TRX 或 USDT 付款时，必须按照订单指定的 TRON 地址、币种和完整数量转账。链上转账不可撤销，网络手续费需另行支付，错误网络或错误信息可能需要人工核验。'] },
      { title: '五、第三方服务', paragraphs: ['MGames 会连接游戏平台、区块链网络和基础设施服务。第三方的可用性和处理速度可能影响服务，访问第三方服务时仍需遵守其规则。'] },
      { title: '六、错误与中断', paragraphs: ['发现异常、重复交易、供应商故障或安全风险时，MGames 可以暂停相关游戏、充值或账号操作并进行核验。页面显示错误时，可以根据真实交易记录修正。'] },
      { title: '七、知识产权', paragraphs: ['MGames 软件、品牌和界面受适用的知识产权法律保护；游戏名称和素材可能归相应游戏平台所有。'] },
      { title: '八、责任', paragraphs: ['请合理使用服务并自行保存重要转账记录。在适用法律允许的范围内，MGames 不对网络故障、第三方中断、设备不兼容或未妥善保管浏览器账号导致的间接损失负责。'] },
      { title: '九、变更与联系', paragraphs: ['服务或法律要求发生变化时，本协议可能更新，最新版本以本页面为准。如有问题，请通过 MGames 公布的支持渠道联系我们。'] },
    ],
  },
  privacy: {
    title: '隐私政策',
    updated: '2026 年 9 月 18 日',
    intro: '本政策说明 MGames 收集哪些信息、使用目的以及你可以作出的选择。',
    sections: [
      { title: '一、收集的信息', paragraphs: ['MGames 可能处理浏览器凭证摘要、用户编号、昵称、语言、IP 地址、设备和浏览器信息、服务日志、游戏记录、钱包记录、充值订单及区块链交易数据。'] },
      { title: '二、使用目的', paragraphs: ['这些信息用于创建和保护账号、启动游戏、维护余额、处理充值、提供支持、防范欺诈、排查故障以及履行适用义务。'] },
      { title: '三、浏览器存储', paragraphs: ['MGames 使用浏览器本地存储保存账号凭证、语言、主题和大厅状态。清除这些数据会重置设置，并可能导致当前浏览器账号无法继续访问。'] },
      { title: '四、服务提供商', paragraphs: ['为完成必要功能，MGames 可能向合作的游戏平台、区块链服务、托管、监控和支持服务商提供所需信息，范围仅限其履行服务所必需的内容。'] },
      { title: '五、保存期限', paragraphs: ['账号、交易和安全记录会在运营服务、解决争议、防范滥用以及满足适用法律或账务要求所需的期限内保存。'] },
      { title: '六、数据安全', paragraphs: ['MGames 使用访问控制、凭证摘要、传输安全和运行监控保护数据。任何网络服务都无法保证绝对安全，你也需要妥善保护设备和浏览器。'] },
      { title: '七、你的选择', paragraphs: ['你可以通过 MGames 公布的支持渠道申请查询或更正可修改的资料，并咨询账号或数据删除。部分交易和安全记录可能依法或因风控需要保留。'] },
      { title: '八、未成年人和跨境处理', paragraphs: ['MGames 不面向未达到所在地法定年龄的人群。数据可能在 MGames 或服务商运营所在的国家和地区处理，并采用适用的保护措施。'] },
      { title: '九、变更与联系', paragraphs: ['最新隐私政策以本页面为准。如有隐私问题，请通过 MGames 公布的支持渠道联系我们。'] },
    ],
  },
}

export const legalDocument = (locale: string, type: LegalType) => locale === 'zh-CN' ? zh[type] : en[type]
