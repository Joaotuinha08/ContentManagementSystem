# Sistema de Gestão de Conteúdos
**Projeto Académico - Sistemas Multimédia Interativos**

Sistema web de gestão de conteúdos desenvolvido em PHP, MySQL e JavaScript que permite aos utilizadores carregar, partilhar e gerir conteúdo multimédia (imagens, vídeos, áudio) com diferentes níveis de permissões baseados em perfis de utilizador.

## Contexto Académico

Este projeto demonstra a implementação de conceitos fundamentais de desenvolvimento web, incluindo:
- **Autenticação e Autorização**: Sistema de roles baseado em perfis
- **API RESTful**: Implementação de endpoints para operações CRUD
- **Segurança Web**: Validação de dados, proteção contra ataques comuns
- **Base de Dados Relacional**: Modelação e implementação em MySQL

## Funcionalidades Implementadas

- **Sistema de Autenticação**: Registo, login e controlo de acesso baseado em roles
- **Gestão de Conteúdo**: Upload e partilha de ficheiros multimédia
- **Hierarquia de Utilizadores**:
  - Convidado (visualização de conteúdo público)
  - Utilizador (upload de conteúdo público, comentários)
  - Simpatizante (criação de categorias, gestão de visibilidade)
  - Administrador (gestão completa do sistema)
- **Sistema de Categorias**: Organização hierárquica do conteúdo
- **Sistema de Comentários**: Interação entre utilizadores
- **API REST**: Interface programática para gestão de conteúdo
- **Interface Responsiva**: Design adaptativo moderno

## Instruções de Utilização

### 1. Configuração da Base de Dados

#### Criação da Base de Dados

```sql
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- Criação da base de dados
CREATE DATABASE IF NOT EXISTS `projeto` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `projeto`;

-- Tabela de utilizadores
CREATE TABLE `Utilizadores` (
  `id_utilizador` int(50) NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) NOT NULL,
  `email` varchar(50) NOT NULL,
  `password` varchar(100) NOT NULL,
  `perfil` enum('administrador','utilizador','simpatizante','convidado') NOT NULL,
  PRIMARY KEY (`id_utilizador`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de categorias
CREATE TABLE `Categoria` (
  `id_categoria` int(50) NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) NOT NULL,
  `tipo` enum('principal','secundaria') NOT NULL,
  `id_criador` int(50) NOT NULL,
  PRIMARY KEY (`id_categoria`),
  KEY `Criador` (`id_criador`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de conteúdo
CREATE TABLE `Conteudo` (
  `id_conteudo` int(50) NOT NULL AUTO_INCREMENT,
  `titulo` varchar(50) NOT NULL,
  `descricao` text NOT NULL,
  `caminho_ficheiro` varchar(255) NOT NULL,
  `tipo` enum('imagem','video','audio') NOT NULL,
  `visibilidade` enum('publico','privado') NOT NULL,
  `data_upload` datetime NOT NULL,
  `id_autor` int(50) NOT NULL,
  `id_categoria` int(50) NOT NULL,
  PRIMARY KEY (`id_conteudo`),
  KEY `Autor` (`id_autor`),
  KEY `Categoria` (`id_categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de comentários
CREATE TABLE `Comentarios` (
  `id_comentario` int(50) NOT NULL AUTO_INCREMENT,
  `id_conteudo` int(50) NOT NULL,
  `id_autor` int(50) NOT NULL,
  `texto` text NOT NULL,
  `data_comentario` datetime NOT NULL,
  PRIMARY KEY (`id_comentario`),
  KEY `Conteudo` (`id_conteudo`),
  KEY `Comentador` (`id_autor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Configuração dos AUTO_INCREMENT
ALTER TABLE `Categoria` MODIFY `id_categoria` int(50) NOT NULL AUTO_INCREMENT;
ALTER TABLE `Comentarios` MODIFY `id_comentario` int(50) NOT NULL AUTO_INCREMENT;
ALTER TABLE `Conteudo` MODIFY `id_conteudo` int(50) NOT NULL AUTO_INCREMENT;
ALTER TABLE `Utilizadores` MODIFY `id_utilizador` int(50) NOT NULL AUTO_INCREMENT;

-- Adição das chaves estrangeiras
ALTER TABLE `Categoria`
  ADD CONSTRAINT `Criador` FOREIGN KEY (`id_criador`) REFERENCES `Utilizadores` (`id_utilizador`);

ALTER TABLE `Comentarios`
  ADD CONSTRAINT `Comentador` FOREIGN KEY (`id_autor`) REFERENCES `Utilizadores` (`id_utilizador`),
  ADD CONSTRAINT `Conteudo` FOREIGN KEY (`id_conteudo`) REFERENCES `Conteudo` (`id_conteudo`);

ALTER TABLE `Conteudo`
  ADD CONSTRAINT `Autor` FOREIGN KEY (`id_autor`) REFERENCES `Utilizadores` (`id_utilizador`),
  ADD CONSTRAINT `Categoria` FOREIGN KEY (`id_categoria`) REFERENCES `Categoria` (`id_categoria`);

COMMIT;
```

### 2. Configuração do Projeto

#### Verificação da Configuração da Base de Dados
O ficheiro [`db.php`](db.php) contém as configurações de ligação:
```php
$host = "localhost";
$port = 3306;
$username = "root";
$password = "";  // Atualizar se necessário
$dbname = "projeto";
```

## Cenários de Teste Sugeridos

#### A. Teste de Autenticação
1. **Registo de Utilizador**: Criar uma nova conta
2. **Login**: Autenticar com credenciais válidas
3. **Controlo de Acesso**: Verificar restrições por perfil

#### B. Teste de Funcionalidades por Perfil

**Como Convidado:**
- Visualizar apenas conteúdo público
- Verificar restrições de acesso

**Como Utilizador Registado:**
- Upload de conteúdo multimédia
- Comentar publicações
- Gestão do próprio conteúdo

**Como Administrador:**
- Gestão de utilizadores
- Controlo total do sistema
- Definir o primeiro administrador manualmente na base de dados

#### C. Teste da API REST
1. Abrir [`teste_api.html`](teste_api.html)
2. Assegurar que tem uma sessão autenticada
3. Executar testes dos endpoints:
   - **GET**: Obter feed de conteúdo em formato JSON
   - **POST**: Criar novo conteúdo
   - **DELETE**: Eliminar conteúdo

## Estrutura do Projeto

```
projeto-smi/
├── api/
│   └── feed.php              # Endpoints da API REST
├── uploads/                  # Diretoria de ficheiros carregados
├── app.php                  # Aplicação principal
├── login.php                # Página de autenticação
├── registo.php              # Página de registo
├── logout.php               # Gestão de logout
├── db.php                   # Configuração da base de dados
├── teste_api.html           # Interface de teste da API
└── README.md                # Este ficheiro
```

## Aspetos Técnicos Relevantes

### Segurança Implementada
- **Hash de Palavras-passe**: Usando `password_hash()` do PHP
- **Prepared Statements**: Proteção contra SQL Injection
- **Validação de Sessões**: Controlo de autenticação
- **Sanitização de Dados**: Validação de inputs do utilizador

### Padrões Web Seguidos
- **RESTful API**: Endpoints seguindo convenções REST
- **HTTP Status Codes**: Respostas adequadas para cada operação
- **JSON**: Formato padrão para comunicação API
- **Responsive Design**: Interface adaptativa