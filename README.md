# Diagnóstico de Conformidade Digital das Igrejas da IPB

Este repositório contém o script e a documentação da análise realizada para identificar o grau de conformidade com a LGPD nos sites declarados por igrejas da Igreja Presbiteriana do Brasil (IPB), conforme os dados públicos disponíveis em:

🔗 https://www.icalvinus.app/consulta_ipb/anuario_igrejas.html

## 🎯 Objetivo

Investigar a maturidade digital das igrejas da IPB quanto à presença de elementos básicos de conformidade com a Lei Geral de Proteção de Dados (LGPD), como políticas de privacidade, avisos de cookies, identificação de encarregado (DPO), e formulários de solicitação de direitos.

## 🛠️ Como foi feito

- O conteúdo HTML da listagem foi salvo manualmente em `tmp.html` para processamento offline.
- O script `parse.php` foi usado para extrair dados estruturados das páginas e armazená-los em um banco SQLite.
- A análise posterior foi feita diretamente sobre o banco de dados gerado, com revisões manuais e inspeções complementares página por página.
- A coluna `website_dados_lgpd` foi enriquecida manualmente com informações sobre conformidade específica.
- Algumas funções PHP foram criadas para auxiliar na atualização e limpeza de dados durante o processo.

## 📁 Estrutura do projeto

```
.
├── parse.php             # Script principal de extração de dados
├── tmp.html              # HTML da listagem da IPB (não incluído no repositório)
├── database.sqlite       # Banco gerado (não incluído por padrão)
└── README.md             # Este arquivo

```

> Obs: Os arquivos `tmp.html` e `database.sqlite` não são incluídos neste repositório por conterem dados derivados que podem ser facilmente reprocessados.

## 📦 Requisitos

- PHP 8.4+ com suporte a SQLite3 e DOMDocument
- Navegador para inspeções manuais
- Ferramenta para acesso a banco de dados SQLite

## 📈 Resultados

Os dados gerados com esse script subsidiaram a tabela presente na seção "Panorama da conformidade digital na IPB" da monografia:

[*A comunhão dos santos frente aos dilemas da ética digital*](https://github.com/vitormattos/monografia-teologia)

## ✍️ Autor

Este script foi desenvolvido por mim (Vitor Mattos) como parte do processo de pesquisa acadêmica do curso de bacharel em Teologia pelo Seminário Teológico Presbiteriano Simonton. Para mais informações, [consulte a monografia](https://github.com/vitormattos/monografia-teologia) ou entre em contato.

