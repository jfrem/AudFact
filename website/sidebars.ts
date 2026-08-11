import type { SidebarsConfig } from '@docusaurus/plugin-content-docs';

const sidebars: SidebarsConfig = {
  mainSidebar: [
    {
      type: 'category',
      label: '🏙️ Visión General',
      items: ['overview', 'BUSINESS', 'domain-glossary'],
    },
    {
      type: 'category',
      label: '⚙️ Arquitectura',
      items: [
        'architecture',
        'architecture-decisions',
        'architecture-diagrams',
        'architecture-executive-report',
        'data-flows',
      ],
    },
    {
      type: 'category',
      label: '🔌 API REST',
      items: ['api-endpoints'],
    },
    {
      type: 'category',
      label: '🗄️ Base de Datos',
      items: ['database-schema'],
    },
    {
      type: 'category',
      label: '🚀 Despliegue',
      items: [
        'deployment-and-ci',
        'deployment-github-actions-lan',
        'docker-operations',
        'high-availability',
        'git-workflow',
      ],
    },
    {
      type: 'category',
      label: '🧙 Auditoría IA',
      items: [
        'audit-findings',
        'audit-identity-contract',
      ],
    },
    {
      type: 'category',
      label: '🧪 Testing',
      items: ['testing-strategy'],
    },

    {
      type: 'category',
      label: '📋 Historial',
      items: ['changelog'],
    },
  ],
};

export default sidebars;
